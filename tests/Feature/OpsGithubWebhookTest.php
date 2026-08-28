<?php

use App\Enums\WorkbookStatus;
use App\Http\Middleware\EnsureGithubSignature;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use Illuminate\Testing\TestResponse;

/*
 * The merge webhook, and the one path by which something other than a human
 * reaches Done.
 *
 * That does not weaken the rule — a merge IS the human's answer, and a session
 * still cannot close its own work. What this file holds is the signature (a
 * webhook door with a weak check is an open door), the idempotency (GitHub
 * redelivers), and the identity guarantee (every one of these payloads carries
 * a login and an email address, and `actor` holds a role).
 */

const WEBHOOK_SECRET = 'a-real-webhook-secret-of-a-believable-length-01234';

beforeEach(function () {
    config(['cfb.github_webhook_secret' => WEBHOOK_SECRET]);
});

/** An issue a session has already taken to review. */
function mergedIssue(): WorkbookItem
{
    $item = WorkbookItem::factory()->create(['status' => WorkbookStatus::InProgress]);
    $item->forceFill(['branch' => $item->branchName()])->save();

    return $item->refresh();
}

/** GitHub's own payload shape, signed the way GitHub signs it. */
function merge(array $payload, ?string $secret = WEBHOOK_SECRET): TestResponse
{
    // Signed over the RAW body, so the test has to build the body itself —
    // re-encoding a parsed array is not byte-identical to what was signed.
    $body = json_encode($payload);

    $headers = ['Content-Type' => 'application/json'];

    if ($secret !== null) {
        $headers[EnsureGithubSignature::HEADER] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return test()->call('POST', '/ops/github', [], [], [], transformHeaders($headers), $body);
}

/**
 * Laravel's header array wants HTTP_ prefixes on a raw `call()` — except
 * CONTENT_TYPE, which PHP puts in the server array bare. Get that wrong and the
 * body never parses, so every payload reads as "not a merge" and every test
 * passes for the wrong reason.
 */
function transformHeaders(array $headers): array
{
    $server = [];

    foreach ($headers as $name => $value) {
        $key = str_replace('-', '_', mb_strtoupper($name));
        $server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_'.$key] = $value;
    }

    return $server;
}

/**
 * The same delivery, form-encoded — which is what GitHub's "Add webhook" form
 * produces by DEFAULT: `payload=<urlencoded json>` instead of a JSON body.
 */
function mergeAsForm(array $payload, ?string $secret = WEBHOOK_SECRET): TestResponse
{
    return formDelivery((string) json_encode($payload), $secret);
}

/**
 * A form-encoded delivery, built the way a real one arrives.
 *
 * BOTH halves have to be supplied. PHP populates `$_POST` from a urlencoded
 * body by itself, but Symfony's `Request::create()` does NOT parse the raw
 * `$content` into parameters — so a test that passes only the body proves the
 * opposite of what it looks like it proves. The signature is checked against
 * the RAW body, the controller reads the PARSED field, and a real delivery has
 * both.
 */
function formDelivery(string $json, ?string $secret = WEBHOOK_SECRET): TestResponse
{
    $body = 'payload='.urlencode($json);

    $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];

    if ($secret !== null) {
        $headers[EnsureGithubSignature::HEADER] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return test()->call('POST', '/ops/github', ['payload' => $json], [], [], transformHeaders($headers), $body);
}

/** @param  array<string, mixed>  $overrides */
function mergePayload(string $branch, array $overrides = []): array
{
    return [
        'action' => 'closed',
        'pull_request' => [
            'merged' => true,
            'head' => ['ref' => $branch],
            // GitHub sends these in every payload. Nothing may store them.
            'user' => ['login' => 'dolly-parton', 'email' => 'dolly@example.test'],
            ...$overrides,
        ],
    ];
}

describe('the signature is the whole authentication', function () {
    it('refuses an unsigned delivery', function () {
        $item = mergedIssue();

        merge(mergePayload($item->branch), secret: null)->assertStatus(401);

        expect($item->fresh()->status)->toBe(WorkbookStatus::InProgress);
    });

    it('refuses a body signed with the wrong secret', function () {
        $item = mergedIssue();

        merge(mergePayload($item->branch), secret: 'a-different-secret-of-a-believable-length-012345')
            ->assertStatus(401);

        expect($item->fresh()->status)->toBe(WorkbookStatus::InProgress);
    });

    it('refuses a body that was edited after it was signed', function () {
        // The whole reason the HMAC is over the raw body: a payload changed in
        // flight must not verify.
        $item = mergedIssue();
        $body = json_encode(mergePayload('some-other-branch'));

        test()->call('POST', '/ops/github', [], [], [], transformHeaders([
            'Content-Type' => 'application/json',
            EnsureGithubSignature::HEADER => 'sha256='.hash_hmac('sha256', $body, WEBHOOK_SECRET),
        ]), json_encode(mergePayload($item->branch)))->assertStatus(401);

        expect($item->fresh()->status)->toBe(WorkbookStatus::InProgress);
    });

    it('does not exist when no secret is configured', function () {
        // 404, not 403 — a door nobody has configured should not announce that
        // it would exist if you guessed. The same rule as EnsureOpsToken.
        config(['cfb.github_webhook_secret' => null]);
        $item = mergedIssue();

        merge(mergePayload($item->branch))->assertStatus(404);
    });

    it('treats a short secret as no secret at all', function () {
        config(['cfb.github_webhook_secret' => 'hunter2']);

        merge(mergePayload(mergedIssue()->branch), secret: 'hunter2')->assertStatus(404);
    });
});

describe('a merge closes its issue', function () {
    it('matches the stored branch and moves the card to Done', function () {
        $item = mergedIssue();

        $response = merge(mergePayload($item->branch))->assertOk();

        expect($response->json('result'))->toBe('done')
            ->and($response->json('issue'))->toBe($item->reference)
            ->and($item->fresh()->status)->toBe(WorkbookStatus::Done)
            ->and($item->fresh()->completed_at)->not->toBeNull();
    });

    it('leaves a closed-but-unmerged pull request alone', function () {
        // Somebody deciding against the work is a person's answer, and the
        // card stays where it is for a person to give it.
        $item = mergedIssue();

        merge(mergePayload($item->branch, ['merged' => false]))->assertOk();

        expect($item->fresh()->status)->toBe(WorkbookStatus::InProgress);
    });

    it('is a normal event for a branch this board never heard of', function () {
        // Always 200. GitHub retries a non-2xx, and every other branch in the
        // repository comes through this door.
        merge(mergePayload('some-unrelated-work'))->assertOk()
            ->assertJson(['result' => 'no_issue']);

        merge(['action' => 'opened'])->assertOk()->assertJson(['result' => 'ignored']);
    });

    it('says nothing the second time, because GitHub redelivers', function () {
        $item = mergedIssue();

        merge(mergePayload($item->branch))->assertOk();
        merge(mergePayload($item->branch))->assertOk();

        expect($item->fresh()->events()->where('kind', WorkbookEvent::MOVED)->count())->toBe(1);
    });

    it('records a role and never the person who clicked merge', function () {
        /*
         * Every one of these payloads carries a login and an email address. If
         * either ever landed in `actor`, the no-identity assertions on the ops
         * reads would be the only thing between an admin's address and a
         * third-party routine.
         */
        $item = mergedIssue();

        merge(mergePayload($item->branch))->assertOk();

        $event = $item->fresh()->events()->where('kind', WorkbookEvent::MOVED)->sole();

        expect($event->actor)->toBe(WorkbookItem::ACTOR_GITHUB)
            ->and($event->note)->toBe('Merged.')
            ->and(json_encode($event->toArray()))
            ->not->toContain('dolly')
            ->not->toContain('@');
    });

    it('is a merge notification and not an editor', function () {
        $item = mergedIssue();

        merge(mergePayload($item->branch, ['title' => 'A different title', 'body' => 'A different body']))->assertOk();

        expect($item->fresh()->title)->toBe($item->title)
            ->and($item->fresh()->body)->toBe($item->body)
            // ...and the branch is never rewritten. It is the durable copy of
            // the reference and it is already in git.
            ->and($item->fresh()->branch)->toBe($item->branch);
    });
});

describe('the content type GitHub defaults to', function () {
    /*
     * This is the failure this block exists for, and it was verified before it
     * was fixed: a correctly-signed merge sent as `x-www-form-urlencoded`
     * answered 200 `ignored`, moved nothing, and showed a GREEN CHECKMARK in
     * GitHub's delivery log. The signature was never the problem — the HMAC is
     * over the raw body either way — the payload was simply somewhere else.
     */

    it('closes the issue when the body arrives form-encoded', function () {
        $item = mergedIssue();

        $response = mergeAsForm(mergePayload($item->branch))->assertOk();

        expect($response->json('result'))->toBe('done')
            ->and($item->fresh()->status)->toBe(WorkbookStatus::Done);
    });

    it('still checks the signature over the form body', function () {
        // Accepting a second shape must not accept a second authentication.
        $item = mergedIssue();

        mergeAsForm(mergePayload($item->branch), secret: 'a-different-secret-of-a-believable-length-012345')
            ->assertStatus(401);

        expect($item->fresh()->status)->toBe(WorkbookStatus::InProgress);
    });

    it('is a no-op rather than a 500 when the payload field is not JSON', function () {
        // A body we did not write must not throw. Malformed JSON decodes to
        // null, casts to an empty array, and falls through to `ignored`.
        $item = mergedIssue();

        formDelivery('{not json at all')->assertOk()->assertJson(['result' => 'ignored']);

        expect($item->fresh()->status)->toBe(WorkbookStatus::InProgress);
    });

    it('leaves a form-encoded unmerged pull request alone too', function () {
        $item = mergedIssue();

        mergeAsForm(mergePayload($item->branch, ['merged' => false]))->assertOk();

        expect($item->fresh()->status)->toBe(WorkbookStatus::InProgress);
    });
});
