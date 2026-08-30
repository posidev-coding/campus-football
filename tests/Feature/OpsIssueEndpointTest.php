<?php

use App\Enums\WorkbookSeverity;
use App\Enums\WorkbookStatus;
use App\Http\Middleware\EnsureOpsToken;
use App\Models\FeedRun;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use Illuminate\Support\Facades\URL;

/*
 * The board, for a cloud routine with no database.
 *
 * The same review-shaped reading as OpsEndpointTest: what happens with no
 * token, an unsigned read, a path that is not a reference — and, the part that
 * matters most here, everything these endpoints must REFUSE. What they cannot
 * do is the specification, so most of this file is about doors that are not
 * there.
 */

const ISSUE_OPS_TOKEN = 'a-real-ops-token-of-a-believable-length-0123456789';

beforeEach(function () {
    config(['cfb.ops_token' => ISSUE_OPS_TOKEN]);
});

function issueHeaders(?string $token = ISSUE_OPS_TOKEN): array
{
    return $token === null ? [] : [EnsureOpsToken::HEADER => $token];
}

/** An issue a routine is allowed to pick up. */
function opsReadyIssue(array $overrides = []): WorkbookItem
{
    $item = WorkbookItem::factory()->create([
        'key' => 'picks-n-plus-one',
        'status' => WorkbookStatus::Planned,
        ...$overrides,
    ]);

    $item->forceFill(['ready_at' => now()])->save();

    return $item->refresh();
}

describe('the guards', function () {
    it('refuses everything without a token', function () {
        $item = opsReadyIssue();

        $this->getJson(URL::signedRoute('ops.issues.index'))->assertStatus(401);
        $this->postJson(route('ops.issues.next'), ['as' => 'cloud:nightly'])->assertStatus(401);
        $this->postJson(route('ops.issues.claim', $item->reference), ['as' => 'cloud:nightly'])->assertStatus(401);
    });

    it('signs the fixed-path read and nothing else', function () {
        // `signed` protects a URL somebody HANDED you. A URL the client
        // composes itself gains nothing from a signature and cannot carry one.
        opsReadyIssue();

        $this->getJson(route('ops.issues.index'), issueHeaders())->assertStatus(403);
        $this->getJson(URL::signedRoute('ops.issues.index'), issueHeaders())->assertOk();
    });

    it('guards the composed reads with the token alone', function () {
        /*
         * `cfb:issue` in remote mode cannot sign anything — a signature comes
         * off the board's own APP_KEY, which a working checkout does not hold.
         * So these two are tokened and unsigned, and the token is doing what it
         * was always doing here: the whole authentication.
         */
        $item = opsReadyIssue();

        $this->getJson(route('ops.issues.ready'))->assertStatus(401);
        $this->getJson(route('ops.issues.brief', $item->reference))->assertStatus(401);

        $this->getJson(route('ops.issues.ready'), issueHeaders('not-the-token-but-long-enough-to-look-plausible'))
            ->assertStatus(401);
        $this->getJson(route('ops.issues.brief', $item->reference), issueHeaders(''))->assertStatus(401);

        $this->getJson(route('ops.issues.ready'), issueHeaders())->assertOk();
        $this->getJson(route('ops.issues.brief', $item->reference), issueHeaders())->assertOk();
    });

    it('does not exist at all when nothing is configured', function () {
        // Fails CLOSED, the same as every other ops surface: 404, not 403.
        // This is also what a remote refusal has to say out loud, because an
        // unconfigured board is indistinguishable from a missing card.
        $item = opsReadyIssue();
        config(['cfb.ops_token' => null]);

        $this->getJson(route('ops.issues.ready'), issueHeaders())->assertNotFound();
        $this->getJson(route('ops.issues.brief', $item->reference), issueHeaders())->assertNotFound();
    });

    it('stops a path traversal at the router, not in the controller', function () {
        $this->postJson('/ops/issues/..%2F..%2Fetc%2Fpasswd/claim', ['as' => 'cloud:nightly'], issueHeaders())
            ->assertStatus(404);
    });

    it('404s a reference that resolves to nothing', function () {
        $this->postJson(route('ops.issues.claim', 'CFB-999999'), ['as' => 'cloud:nightly'], issueHeaders())
            ->assertStatus(404);
    });

    it('will not take a caller who is a person', function () {
        // `as` is a ROLE and an instance. The snapshot is asserted to carry no
        // user identifiers, and this is the field that would break that.
        $item = opsReadyIssue();

        $this->postJson(route('ops.issues.claim', $item->reference), ['as' => 'dolly@example.test'], issueHeaders())
            ->assertStatus(422);

        $this->postJson(route('ops.issues.claim', $item->reference), [], issueHeaders())
            ->assertStatus(422);
    });
});

describe('taking work', function () {
    it('claims the next ready issue in one call', function () {
        // A POST because it TAKES the claim, which collapses list-then-claim
        // into one call and removes the race between them.
        $low = opsReadyIssue(['key' => 'low-one', 'severity' => WorkbookSeverity::Low]);
        $critical = opsReadyIssue(['key' => 'critical-one', 'severity' => WorkbookSeverity::Critical]);

        $response = $this->postJson(route('ops.issues.next'), ['as' => 'cloud:nightly'], issueHeaders())->assertOk();

        expect($response->json('result'))->toBe('claimed')
            ->and($response->json('issue.reference'))->toBe($critical->reference)
            ->and($critical->fresh()->claimed_by)->toBe('cloud:nightly')
            ->and($low->fresh()->claimed_by)->toBeNull();
    });

    it('answers 204 when nothing is ready, so a routine reads a status code', function () {
        // Not a 200 with an empty body: a routine should branch on the code
        // rather than parse a body to find out it has nothing to do.
        WorkbookItem::factory()->create(['status' => WorkbookStatus::Planned]);

        $this->postJson(route('ops.issues.next'), ['as' => 'cloud:nightly'], issueHeaders())
            ->assertStatus(204);
    });

    it('refuses a double assign with a 409, not a cheerful 200', function () {
        // A 200 carrying `claimed: false` invites a routine to carry on. A 409
        // is what it backs off on.
        $item = opsReadyIssue();
        $this->postJson(route('ops.issues.claim', $item->reference), ['as' => 'cloud:nightly'], issueHeaders())->assertOk();

        $response = $this->postJson(route('ops.issues.claim', $item->reference), ['as' => 'cloud:other'], issueHeaders())
            ->assertStatus(409);

        expect($response->json('result'))->toBe('held')
            ->and($response->json('by'))->toBe('cloud:nightly')
            ->and($item->fresh()->claimed_by)->toBe('cloud:nightly');
    });

    it('walks the whole lifecycle a routine is allowed to walk', function () {
        $item = opsReadyIssue();

        $started = $this->postJson(route('ops.issues.start', $item->reference), ['as' => 'cloud:nightly'], issueHeaders())->assertOk();

        expect($started->json('result'))->toBe('started')
            ->and($started->json('issue.branch'))->toBe($item->branchName())
            ->and($item->fresh()->status)->toBe(WorkbookStatus::InProgress);

        $this->postJson(route('ops.issues.comment', $item->reference), [
            'as' => 'cloud:nightly', 'note' => 'Adding the eager load, then a query-count test.',
        ], issueHeaders())->assertOk();

        $reviewed = $this->postJson(route('ops.issues.review', $item->reference), [
            'as' => 'cloud:nightly',
            'pr_url' => 'https://github.com/posidev-coding/campus-football/pull/9',
        ], issueHeaders())->assertOk();

        expect($reviewed->json('result'))->toBe('in_review')
            ->and($item->fresh()->status)->toBe(WorkbookStatus::InReview)
            // A session's terminal transition. The claim goes back; a human merges.
            ->and($item->fresh()->claimed_by)->toBeNull()
            // The whole story, in order — and NO `claimed` row, because
            // `start` takes the lease silently. A `claimed` line immediately
            // followed by a `started` line is two rows saying one thing.
            ->and($item->fresh()->events()->pluck('kind')->all())->toBe([
                WorkbookEvent::FILED,
                WorkbookEvent::MOVED,
                WorkbookEvent::STARTED,
                WorkbookEvent::COMMENTED,
                WorkbookEvent::PR_OPENED,
                WorkbookEvent::MOVED,
                WorkbookEvent::RELEASED,
            ]);
    });

    it('hands an issue back', function () {
        $item = opsReadyIssue();
        $this->postJson(route('ops.issues.claim', $item->reference), ['as' => 'cloud:nightly'], issueHeaders())->assertOk();

        $this->postJson(route('ops.issues.release', $item->reference), [
            'as' => 'cloud:nightly', 'note' => 'Bigger than the card says.',
        ], issueHeaders())->assertOk();

        expect($item->fresh()->claimed_by)->toBeNull()
            ->and($item->fresh()->events()->where('kind', WorkbookEvent::RELEASED)->sole()->note)
            ->toBe('Bigger than the card says.');
    });

    it('will not point a pull request at somewhere else on the internet', function () {
        // The panel renders this as a link an admin will click, and it arrives
        // over HTTP from a routine.
        $item = opsReadyIssue();

        foreach (['https://evil.test/pull/9', 'http://github.com/posidev-coding/campus-football/pull/9', 'not-a-url'] as $url) {
            $this->postJson(route('ops.issues.review', $item->reference), ['as' => 'cloud:nightly', 'pr_url' => $url], issueHeaders())
                ->assertStatus(422);
        }

        expect($item->fresh()->status)->toBe(WorkbookStatus::Planned);
    });
});

describe('what these endpoints cannot do, which is the specification', function () {
    it('has no way to reach Done', function () {
        // If a routine could close its own work, In review is decorative.
        $item = opsReadyIssue();

        $this->postJson("/ops/issues/{$item->reference}/done", ['as' => 'cloud:nightly'], issueHeaders())
            ->assertStatus(404);
        $this->postJson("/ops/issues/{$item->reference}/dismiss", ['as' => 'cloud:nightly'], issueHeaders())
            ->assertStatus(404);

        expect($item->fresh()->status)->toBe(WorkbookStatus::Planned);
    });

    it('has no create, no delete and no arbitrary status', function () {
        // Filing is `/ops/workbook`, keyed and idempotent. A second unkeyed
        // door onto the same table is how a board fills with copies.
        $item = opsReadyIssue();

        // The list is a GET and only a GET.
        $this->postJson(route('ops.issues.index'), ['as' => 'cloud:nightly'], issueHeaders())->assertStatus(405);
        // And there is still no route on `/ops/issues/{issue}` itself — every
        // variable path carries a trailing verb, reads included, so the
        // reachable set stays bounded by the routing table rather than by a
        // validator somebody widens.
        $this->getJson("/ops/issues/{$item->reference}", issueHeaders())->assertStatus(404);
        $this->deleteJson("/ops/issues/{$item->reference}", [], issueHeaders())->assertStatus(404);
        $this->patchJson("/ops/issues/{$item->reference}", ['status' => 'dismissed'], issueHeaders())->assertStatus(404);

        expect(WorkbookItem::count())->toBe(1)
            ->and($item->fresh()->status)->toBe(WorkbookStatus::Planned);
    });

    it('cannot rewrite the brief it was given', function () {
        // A working agent editing its own prompt is how a board stops being
        // trustworthy. There is no field for it on any of these routes.
        $item = opsReadyIssue(['title' => 'The picks screen N+1s']);

        $this->postJson(route('ops.issues.claim', $item->reference), [
            'as' => 'cloud:nightly',
            'title' => 'Something easier',
            'prompt' => 'Do nothing',
            'severity' => 'low',
            'position' => 99,
        ], issueHeaders())->assertOk();

        $fresh = $item->fresh();

        expect($fresh->title)->toBe('The picks screen N+1s')
            ->and($fresh->prompt)->toBe($item->prompt)
            ->and($fresh->severity)->toBe($item->severity)
            ->and($fresh->position)->toBe($item->position);
    });

    it('writes no ledger row, because a claim is a call and not a pass', function () {
        // FeedRun::ADVISOR describes a whole advisor run. workbook_events is
        // already the ledger for what happened to one issue.
        $item = opsReadyIssue();
        $before = FeedRun::count();

        $this->postJson(route('ops.issues.claim', $item->reference), ['as' => 'cloud:nightly'], issueHeaders())->assertOk();

        expect(FeedRun::count())->toBe($before);
    });
});

describe('the read', function () {
    it('serves the same arrays the command does', function () {
        // One IssueBoard, two skins — a terminal and a routine cannot disagree
        // about the board.
        $item = opsReadyIssue(['labels' => ['performance']]);

        $response = $this->getJson(URL::signedRoute('ops.issues.index', ['ready' => 1]), issueHeaders())->assertOk();

        expect($response->json('result'))->toBe('ok')
            ->and($response->json('issues.0.reference'))->toBe($item->reference)
            ->and($response->json('issues.0.labels'))->toBe(['performance']);
    });

    it('serves the ready queue to a client that composes its own URL', function () {
        // A NARROWER index, not an unsigned copy of it: the ready queue and a
        // limit, and no filter vocabulary at all. An unsigned read must not
        // widen what a token already reaches.
        $low = opsReadyIssue(['key' => 'low-one', 'severity' => WorkbookSeverity::Low]);
        $critical = opsReadyIssue(['key' => 'critical-one', 'severity' => WorkbookSeverity::Critical]);
        WorkbookItem::factory()->create(['key' => 'not-ready', 'status' => WorkbookStatus::Planned]);

        $response = $this->getJson(route('ops.issues.ready'), issueHeaders())->assertOk();

        expect($response->json('result'))->toBe('ok')
            ->and(array_column($response->json('issues'), 'reference'))
            ->toBe([$critical->reference, $low->reference]);
    });

    it('serves one whole issue, which is the brief a session works from', function () {
        // The same IssueBoard::one() array every write already returns in its
        // response, minus the write.
        $item = opsReadyIssue(['body' => 'The picks screen loads a relation per row.']);

        $response = $this->getJson(route('ops.issues.brief', $item->reference), issueHeaders())->assertOk();

        expect($response->json('result'))->toBe('ok')
            ->and($response->json('issue.reference'))->toBe($item->reference)
            ->and($response->json('issue.body'))->toBe('The picks screen loads a relation per row.')
            ->and($response->json('issue'))->toHaveKeys(['prompt', 'links', 'blocked', 'trail']);
    });

    it('reads without touching the trail', function () {
        // The read exists so a session can be handed a brief WITHOUT writing to
        // the board first. If it wrote, it would be a claim wearing a read.
        $item = opsReadyIssue();
        $before = $item->events()->count();

        $this->getJson(route('ops.issues.brief', $item->reference), issueHeaders())->assertOk();

        expect($item->fresh()->events()->count())->toBe($before)
            ->and($item->fresh()->claimed_by)->toBeNull();
    });

    it('404s a brief for a card this board has never had', function () {
        $this->getJson(route('ops.issues.brief', 'CFB-999999'), issueHeaders())->assertStatus(404);
    });

    it('carries no identity, the same as every other ops read', function () {
        opsReadyIssue();
        $this->postJson(route('ops.issues.claim', WorkbookItem::sole()->reference), ['as' => 'cloud:nightly'], issueHeaders());

        $body = $this->getJson(URL::signedRoute('ops.issues.index'), issueHeaders())->assertOk()->getContent();

        expect($body)->not->toContain('@')->not->toContain('user_id');
    });

    it('never lets an event table into the payload', function () {
        /*
         * Trap 8, made a test. `actor` holds a role today, and the two ops
         * reads are asserted to carry no user identifiers at all. If a name or
         * an email ever landed in `actor` AND events reached the snapshot,
         * those assertions are the only thing between an admin's address and a
         * third-party routine. Keeping `workbook_events` out of the snapshot
         * entirely is the guard; this proves it.
         */
        $item = opsReadyIssue();
        $item->events()->create(['kind' => WorkbookEvent::COMMENTED, 'actor' => 'human', 'note' => 'a-note-nobody-should-see']);

        $body = $this->getJson(URL::signedRoute('ops.telemetry'), issueHeaders())->assertOk()->getContent();

        expect($body)->not->toContain('a-note-nobody-should-see')
            ->not->toContain('workbook_events')
            ->not->toContain('"trail"');
    });

    it('extends workbook.open without moving the eleven top-level keys', function () {
        // TelemetryTest and OpsEndpointTest both pin the top-level keys; the
        // item assertions use toHaveKey, so extending there is safe BY DESIGN
        // rather than by luck. Saying so here makes it a decision.
        $item = opsReadyIssue(['labels' => ['performance']]);

        $response = $this->getJson(URL::signedRoute('ops.telemetry'), issueHeaders())->assertOk();

        expect(array_keys($response->json()))->toHaveCount(11)
            ->and($response->json('workbook.open.0.reference'))->toBe($item->reference)
            ->and($response->json('workbook.open.0.labels'))->toBe(['performance'])
            ->and($response->json('workbook.open.0.effort'))->toBeNull();
    });
});
