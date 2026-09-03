<?php

use App\Enums\WorkbookStatus;
use App\Http\Controllers\Ops\WorkbookController;
use App\Http\Middleware\EnsureOpsToken;
use App\Models\ClientError;
use App\Models\FeedRun;
use App\Models\User;
use App\Models\WorkbookItem;
use Illuminate\Support\Facades\URL;

/*
 * The two `/ops` surfaces — the ONLY externally-reachable endpoints the AI
 * layer adds, and the only place in the application a machine can write.
 *
 * The plan flagged these for review, so this file is written the way a review
 * would read it: what happens with no token, a wrong token, an unconfigured
 * token, a weak token, an unsigned URL, a tampered URL, and a payload that
 * asks for more than the endpoint is allowed to give.
 */

/** A real-shaped secret. Anything shorter is treated as unset. */
const OPS_TOKEN = 'a-real-ops-token-of-a-believable-length-0123456789';

beforeEach(function () {
    config(['cfb.ops_token' => OPS_TOKEN]);
    $this->travelTo('2026-09-05 18:00:00');
});

function telemetryUrl(): string
{
    return URL::signedRoute('ops.telemetry');
}

function opsHeaders(?string $token = OPS_TOKEN): array
{
    return $token === null ? [] : [EnsureOpsToken::HEADER => $token];
}

describe('the token is the whole authentication', function () {
    it('refuses a request carrying no token', function () {
        $this->getJson(telemetryUrl())->assertStatus(401);
        $this->postJson(route('ops.workbook'), ['items' => []])->assertStatus(401);
    });

    it('refuses a wrong token', function () {
        $this->getJson(telemetryUrl(), opsHeaders('not-the-token-but-long-enough-to-look-plausible'))
            ->assertStatus(401);
    });

    it('refuses an empty token header', function () {
        $this->getJson(telemetryUrl(), opsHeaders(''))->assertStatus(401);
    });

    it('does not exist at all when nothing is configured', function () {
        // 404, not 403. A 403 tells an unauthenticated stranger there is
        // something here worth guessing at. This is also the FAIL-CLOSED case:
        // the naive version compares a null header to a null config and lets
        // everybody through.
        config(['cfb.ops_token' => null]);

        $this->getJson(telemetryUrl())->assertNotFound();
        $this->getJson(telemetryUrl(), opsHeaders())->assertNotFound();
        $this->postJson(route('ops.workbook'), ['items' => []], opsHeaders())->assertNotFound();
    });

    it('treats a weak token as no token at all', function () {
        // `OPS_TOKEN=test` is how a secret stops being one, and an ops
        // endpoint is not where that should be discovered.
        config(['cfb.ops_token' => str_repeat('a', EnsureOpsToken::MINIMUM_LENGTH - 1)]);

        $this->getJson(telemetryUrl(), [EnsureOpsToken::HEADER => str_repeat('a', EnsureOpsToken::MINIMUM_LENGTH - 1)])
            ->assertNotFound();
    });

    it('admits the right token', function () {
        $this->getJson(telemetryUrl(), opsHeaders())->assertOk();
    });
});

describe('the read is signed as well as tokened', function () {
    it('refuses an unsigned URL even with the right token', function () {
        // The URL is the thing that ends up in a routine's configuration, a
        // shell history and a log line.
        $this->getJson('/ops/telemetry', opsHeaders())->assertForbidden();
    });

    it('refuses a URL edited after it was signed', function () {
        $tampered = telemetryUrl().'&extra=1';

        $this->getJson($tampered, opsHeaders())->assertForbidden();
    });

    it('leaves the write unsigned, because the advisor composes it', function () {
        // Nothing hands the routine this URL to follow, and `signed` does not
        // cover a POST body in any case. The token and the validator are the
        // guards there.
        $this->postJson(route('ops.workbook'), ['items' => []], opsHeaders())->assertOk();
    });
});

describe('the read carries no identity', function () {
    it('serves the same payload cfb:telemetry does, and no user', function () {
        $user = User::factory()->create([
            'id' => 987654, 'email' => 'dolly@example.test', 'handle' => 'jolene',
        ]);
        ClientError::create([
            'fingerprint' => str_repeat('a', 40), 'kind' => 'error',
            'message' => 'Cannot read properties of undefined',
            'reports' => 12, 'path' => '/picks', 'user_id' => $user->id,
        ]);

        $response = $this->getJson(telemetryUrl(), opsHeaders())->assertOk();

        expect(array_keys($response->json()))->toBe([
            'generated_at', 'window_hours', 'season', 'ops', 'coverage',
            'pickem', 'schedule', 'errors', 'performance', 'funnel', 'funnel_since', 'workbook',
        ]);

        $body = $response->getContent();

        expect($body)
            ->not->toContain('dolly@example.test')
            ->not->toContain('jolene')
            ->not->toContain('987654')
            ->not->toContain('user_id');
    });

    it('writes nothing', function () {
        $before = FeedRun::count();

        $this->getJson(telemetryUrl(), opsHeaders())->assertOk();

        expect(FeedRun::count())->toBe($before)
            ->and(WorkbookItem::count())->toBe(0);
    });
});

describe('the write files a pass', function () {
    it('files items and records one ledger row for the run', function () {
        $response = $this->postJson(route('ops.workbook'), [
            'items' => [
                [
                    'key' => 'picks-n-plus-one',
                    'title' => 'The picks screen N+1s on slate.games.team',
                    'body' => 'Measured over the last week of slow queries.',
                    'category' => 'performance',
                    'severity' => 'high',
                    'evidence' => ['hits' => 214],
                    'prompt' => 'Add the eager load and prove it with a query-count test.',
                ],
                [
                    'key' => 'rankings-stale',
                    'title' => 'Rankings have not synced in eighteen days',
                    'category' => 'data',
                    'severity' => 'medium',
                ],
            ],
            'duration_ms' => 42_000,
        ], opsHeaders())->assertOk();

        expect($response->json('filed'))->toBe(2)
            ->and(WorkbookItem::count())->toBe(2)
            ->and(WorkbookItem::where('key', 'picks-n-plus-one')->sole()->evidence)->toBe(['hits' => 214]);

        // ONE row for the whole pass, which is what lets Sync Health say
        // "the advisor last ran an hour ago and filed two things".
        $run = FeedRun::latestAdvisorRun();

        expect($run->status)->toBe(FeedRun::COMPLETE)
            ->and($run->records)->toBe(2)
            ->and($run->duration_ms)->toBe(42_000);
    });

    it('is idempotent by key, however many times the routine runs', function () {
        $payload = ['items' => [[
            'key' => 'picks-n-plus-one', 'title' => 'Same finding',
            'category' => 'performance', 'severity' => 'high',
        ]]];

        $this->postJson(route('ops.workbook'), $payload, opsHeaders())->assertOk();
        $this->postJson(route('ops.workbook'), $payload, opsHeaders())->assertOk();
        $this->postJson(route('ops.workbook'), $payload, opsHeaders())->assertOk();

        expect(WorkbookItem::count())->toBe(1)
            ->and(FeedRun::where('command', FeedRun::ADVISOR)->count())->toBe(3);
    });

    it('cannot reopen what a human dismissed', function () {
        // The guard lives in WorkbookItem::propose(), so it holds for every
        // caller and not only this one — but the endpoint is where it matters,
        // because the advisor is the thing that keeps re-finding it.
        WorkbookItem::factory()->dismissed()->create(['key' => 'wont-fix', 'title' => 'Answered already']);

        $response = $this->postJson(route('ops.workbook'), ['items' => [[
            'key' => 'wont-fix', 'title' => 'Reopening this please',
            'category' => 'bug', 'severity' => 'critical',
        ]]], opsHeaders())->assertOk();

        expect(WorkbookItem::sole()->status)->toBe(WorkbookStatus::Dismissed)
            ->and(WorkbookItem::sole()->title)->toBe('Answered already')
            // ...and the advisor is TOLD, so the next pass can stop proposing it.
            ->and($response->json('items.wont-fix'))->toBe('dismissed');
    });

    it('records a failed pass, so a dead routine is not silence', function () {
        // Without this, a routine that died is indistinguishable from one that
        // never ran, and "last run" going quietly stale is the failure mode a
        // ledger exists to prevent.
        $this->postJson(route('ops.workbook'), [
            'error' => 'the telemetry endpoint timed out',
        ], opsHeaders())->assertStatus(202);

        $run = FeedRun::latestAdvisorRun();

        expect($run->status)->toBe(FeedRun::FAILED)
            ->and($run->error)->toBe('the telemetry endpoint timed out')
            ->and(WorkbookItem::count())->toBe(0);
    });
});

describe('the write cannot reach past the workbook', function () {
    it('refuses a category or severity outside the vocabulary', function () {
        $this->postJson(route('ops.workbook'), ['items' => [[
            'key' => 'made-up', 'title' => 'A finding',
            'category' => 'existential', 'severity' => 'apocalyptic',
        ]]], opsHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.category', 'items.0.severity']);

        expect(WorkbookItem::count())->toBe(0);
    });

    it('ignores a status or a position the payload tries to set', function () {
        // Where an item sits on the board is a human's answer. The advisor
        // files findings; it does not plan the week.
        $this->postJson(route('ops.workbook'), ['items' => [[
            'key' => 'sneaky', 'title' => 'A finding',
            'category' => 'bug', 'severity' => 'low',
            'status' => 'done', 'position' => 99, 'source' => 'human',
        ]]], opsHeaders())->assertOk();

        $item = WorkbookItem::sole();

        expect($item->status)->toBe(WorkbookStatus::Inbox)
            ->and($item->position)->toBe(1)
            ->and($item->source)->toBe(WorkbookItem::SOURCE_ADVISOR);
    });

    it('refuses a key that is not a slug', function () {
        $this->postJson(route('ops.workbook'), ['items' => [[
            'key' => '../../etc/passwd', 'title' => 'A finding',
            'category' => 'bug', 'severity' => 'low',
        ]]], opsHeaders())->assertStatus(422);
    });

    it('caps how much one pass may file', function () {
        $items = collect(range(1, WorkbookController::MAX_ITEMS + 1))
            ->map(fn (int $i): array => [
                'key' => "finding-{$i}", 'title' => "Finding {$i}",
                'category' => 'bug', 'severity' => 'low',
            ])->all();

        $this->postJson(route('ops.workbook'), ['items' => $items], opsHeaders())
            ->assertStatus(422);

        expect(WorkbookItem::count())->toBe(0);
    });

    it('needs either items or an error, never neither', function () {
        $this->postJson(route('ops.workbook'), [], opsHeaders())->assertStatus(422);
    });
});

describe('both surfaces are throttled', function () {
    it('caps attempts by IP, whether or not the token is right', function () {
        // The throttle runs BEFORE the token check, so a brute force is
        // stopped by the limiter rather than by luck.
        foreach (range(1, 30) as $i) {
            $this->getJson(telemetryUrl(), opsHeaders('wrong-token-but-long-enough-to-be-plausible'));
        }

        $this->getJson(telemetryUrl(), opsHeaders())->assertStatus(429);
    });
});

describe('neither surface starts a session', function () {
    it('sets no session cookie, because there is no user here', function () {
        // Registered outside the `web` group on purpose: no cookies, no
        // session start, and no CSRF exemption to be widened later.
        $response = $this->getJson(telemetryUrl(), opsHeaders())->assertOk();

        expect($response->headers->getCookies())->toBe([]);
    });
});
