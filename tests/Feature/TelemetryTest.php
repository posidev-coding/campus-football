<?php

use App\Actions\RecordUxEvent;
use App\Enums\UxSignal;
use App\Enums\WorkbookStatus;
use App\Jobs\FetchGameSummary;
use App\Models\ClientError;
use App\Models\FeedRun;
use App\Models\User;
use App\Models\UxEvent;
use App\Models\WorkbookItem;
use App\Support\OpsReport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
 * The snapshot the maintenance advisor reads.
 *
 * The advisor is a Claude Code routine with NO database access: it reads the
 * repository and this payload, and the quality of what it proposes is bounded
 * by what is in here. Two rules, and they are the whole design — aggregate
 * only, and it reports rather than fixes.
 */

beforeEach(function () {
    Redis::connection('pulse')->flushdb();
    $this->travelTo('2026-09-05 18:00:00');
});

/** The snapshot, decoded. */
function telemetry(): array
{
    Artisan::call('cfb:telemetry', ['--json' => true]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

describe('the payload', function () {
    it('carries every section the advisor needs', function () {
        expect(array_keys(telemetry()))->toBe([
            'generated_at', 'window_hours', 'season', 'ops', 'coverage',
            'pickem', 'schedule', 'errors', 'performance', 'funnel', 'funnel_since', 'workbook',
        ]);
    });

    it('is valid JSON and nothing but JSON', function () {
        // The `/ops/telemetry` route will serve this verbatim; a stray line of
        // console output would make it unparseable at the other end.
        Artisan::call('cfb:telemetry', ['--json' => true]);

        expect(json_decode(Artisan::output(), true))->toBeArray();
    });

    it('reads the season from the calendar and never from config', function () {
        // A season exists in the database months before it is played, and
        // currentYear/resultsYear answer different questions.
        expect(telemetry()['season'])
            ->toHaveKeys(['current_year', 'results_year', 'phase']);
    });
});

describe('no user ever reaches the payload', function () {
    it('carries counts and not people, even when every sensor has fired', function () {
        // This leaves the machine. A snapshot that carries identity is a
        // snapshot that cannot be handed to anything.
        $user = User::factory()->create([
            'id' => 987654,
            'email' => 'dolly@example.test',
            'handle' => 'jolene',
            'first_name' => 'Dolly',
        ]);

        ClientError::create([
            'fingerprint' => str_repeat('a', 40), 'kind' => 'error',
            'message' => 'Cannot read properties of undefined', 'reports' => 12,
            'path' => '/picks', 'user_id' => $user->id,
        ]);

        FeedRun::jobFailed(FetchGameSummary::class, 'ESPN returned 403');
        app(RecordUxEvent::class)->handle(UxSignal::FirstPickMade);

        $payload = json_encode(telemetry());

        expect($payload)
            ->not->toContain('dolly@example.test')
            ->not->toContain('jolene')
            ->not->toContain('987654')
            ->not->toContain('user_id')
            ->not->toContain('"email"');
    });

    it('drops the Eloquent models the schedule report hands back', function () {
        // SyncSchedule::tasks() returns a FeedRun instance for the admin
        // table; serializing one would drag every column into the payload.
        $task = telemetry()['schedule'][0];

        expect(array_keys($task))->toBe([
            'name', 'cadence', 'gated', 'overdue', 'last_status', 'last_run_at',
        ]);
    });
});

describe('what it reports', function () {
    it('separates a failed command from a failed job', function () {
        // The `job:` rows exist because Cloud's managed queues hide
        // failed_jobs; the split is what makes them readable as two problems.
        FeedRun::jobFailed(FetchGameSummary::class, 'ESPN returned 403');

        $run = FeedRun::begin('sync:news', 2026);
        $run->fail('the feed timed out', 1, 900);

        $errors = telemetry()['errors'];

        expect($errors['jobs'])->toHaveCount(1)
            ->and($errors['jobs'][0]['command'])->toBe('job:FetchGameSummary')
            ->and($errors['commands'])->toHaveCount(1)
            ->and($errors['commands'][0]['command'])->toBe('sync:news');
    });

    it('groups Pulse entries so one slow route is one line', function () {
        // A route that is slow two hundred times is one finding, not two
        // hundred — and the payload has to fit in a prompt.
        foreach (range(1, 5) as $i) {
            DB::table('pulse_entries')->insert([
                'timestamp' => now()->subMinutes(10)->getTimestamp(),
                'type' => 'slow_request',
                'key' => '["GET","/picks"]',
                'value' => 1_000 + $i,
            ]);
        }

        $slow = telemetry()['performance']['slow_request'];

        expect($slow)->toHaveCount(1)
            ->and($slow[0])->toBe(['what' => 'GET /picks', 'hits' => 5, 'worst' => 1_005]);
    });

    it('never reports a timestamp in a field measured in milliseconds', function () {
        /*
         * One query shape serves five Pulse types, and `value` does not mean
         * the same thing in all five: it is a DURATION for the four slow_*
         * types and the OCCURRENCE TIMESTAMP for `exception`
         * (`Recorders/Exceptions.php`, `value: $timestamp`).
         *
         * So the snapshot reported `"worst": 1787646322` on an exception row —
         * a unix time in a field every sibling row measures in ms — to a
         * consumer with no database access that is explicitly told never to
         * invent a number. Handing it that invites exactly one mistake:
         * reporting a twenty-day exception.
         *
         * Both types are seeded together on purpose. Fixing this by renaming
         * the field for everyone would pass a test that only looked at
         * exceptions.
         */
        $thrownAt = now()->subMinutes(30)->startOfSecond();

        DB::table('pulse_entries')->insert([
            [
                'timestamp' => now()->subMinutes(10)->getTimestamp(),
                'type' => 'slow_request',
                'key' => '["GET","/picks"]',
                'value' => 1_005,
            ],
            [
                'timestamp' => $thrownAt->getTimestamp(),
                'type' => 'exception',
                'key' => '["RedisException","vendor\/laravel\/framework\/src\/Illuminate\/Redis\/Connectors\/PhpRedisConnector.php:185"]',
                // What Pulse actually writes: the occurrence timestamp.
                'value' => $thrownAt->getTimestamp(),
            ],
        ]);

        $performance = telemetry()['performance'];

        expect($performance['exception'][0])
            // Named for what it is, and readable without a decoder.
            ->toHaveKey('last_seen_at')
            ->and($performance['exception'][0]['last_seen_at'])->toBe($thrownAt->toIso8601String())
            // OMITTED, not zeroed. A missing measurement is skipped, never
            // substituted — a `worst` of 0 here is the invented value the
            // whole rule exists to stop.
            ->and($performance['exception'][0])->not->toHaveKey('worst')
            // ...and the four types that DO measure a duration are untouched.
            ->and($performance['slow_request'][0]['worst'])->toBe(1_005)
            ->and($performance['slow_request'][0])->not->toHaveKey('last_seen_at');
    });

    it('emits a closed set of keys for each Pulse row shape', function () {
        /*
         * The same guard as the schedule report's below: a row leaves this
         * machine, so what is IN it is pinned rather than trusted. It is also
         * what stops the fix above from being undone by addition — putting
         * `worst` back alongside `last_seen_at` would fail here even though
         * every other assertion still passed.
         *
         * `pulse_entries` carries no user column at all (id, timestamp, type,
         * key, key_hash, value), so identity cannot leak from a row — only
         * from `key`, which `OpsReport::readableKey()` truncates to two parts.
         */
        DB::table('pulse_entries')->insert([
            [
                'timestamp' => now()->subMinutes(10)->getTimestamp(),
                'type' => 'slow_query',
                'key' => '["select * from `games`","app/Support/Thing.php:12"]',
                'value' => 2_400,
            ],
            [
                'timestamp' => now()->subMinutes(10)->getTimestamp(),
                'type' => 'exception',
                'key' => '["RuntimeException","app/Support/Thing.php:12"]',
                'value' => now()->subMinutes(10)->getTimestamp(),
            ],
        ]);

        $performance = telemetry()['performance'];

        expect(array_keys($performance['slow_query'][0]))->toBe(['what', 'hits', 'worst'])
            ->and(array_keys($performance['exception'][0]))->toBe(['what', 'hits', 'last_seen_at']);
    });

    it('carries the browser errors no server-side monitor can see', function () {
        ClientError::create([
            'fingerprint' => str_repeat('b', 40), 'kind' => 'unhandledrejection',
            'message' => 'Failed to fetch', 'reports' => 4_000,
            'path' => '/scoreboard', 'viewport' => 390, 'standalone' => true,
        ]);

        $client = telemetry()['errors']['client'];

        expect($client[0]['message'])->toBe('Failed to fetch')
            ->and($client[0]['reports'])->toBe(4_000)
            // Width and standalone are the first two triage questions on a
            // product designed at 390px and installed as a PWA.
            ->and($client[0]['viewport'])->toBe(390)
            ->and($client[0]['standalone'])->toBeTrue();
    });

    it('adds today to the persisted funnel, so the two halves agree', function () {
        UxEvent::create(['day' => '2026-09-04', 'signal' => 'invite_opened', 'count' => 9]);
        app(RecordUxEvent::class)->handle(UxSignal::InviteOpened);

        expect(telemetry()['funnel']['invite_opened'])->toBe(10);
    });

    it('names every signal, including the ones at zero', function () {
        // A missing key and a zero read the same to a human and differently
        // to a model. Every signal is always present.
        expect(array_keys(telemetry()['funnel']))
            ->toBe(array_map(fn (UxSignal $s) => $s->value, UxSignal::cases()));
    });

    it('says since when each total has been counting, and a zero row counts as a day', function () {
        /*
         * The seven-day total is a seven-day number only for a signal that
         * has been counting for seven days. onboarding_credentials_reached
         * read 0 beside 163 opened and was filed as the wizard losing
         * everybody, when it had shipped two days into the window. The
         * earliest row in the window — zero included, which the rollup now
         * writes — is the day the deployed code started counting.
         */
        UxEvent::create(['day' => '2026-09-01', 'signal' => 'invite_opened', 'count' => 0]);
        UxEvent::create(['day' => '2026-09-03', 'signal' => 'invite_opened', 'count' => 4]);
        UxEvent::create(['day' => '2026-09-03', 'signal' => 'onboarding_credentials_reached', 'count' => 0]);

        $since = telemetry()['funnel_since'];

        expect($since['invite_opened'])->toBe('2026-09-01')
            ->and($since['onboarding_credentials_reached'])->toBe('2026-09-03')
            ->and(array_keys($since))->toBe(array_map(fn (UxSignal $s) => $s->value, UxSignal::cases()));
    });

    it('dates a signal with no persisted day to today, which is all its total covers', function () {
        // Nothing rolled up yet, so the total is today's Redis count alone.
        // Saying "since today" is exact; inventing an earlier date is the
        // fabricated number docs/product.md rules out.
        app(RecordUxEvent::class)->handle(UxSignal::OnboardingCredentialsReached);

        expect(telemetry()['funnel']['onboarding_credentials_reached'])->toBe(1)
            ->and(telemetry()['funnel_since']['onboarding_credentials_reached'])->toBe('2026-09-05');
    });

    it('prints the date beside each signal at the terminal', function () {
        UxEvent::create(['day' => '2026-09-02', 'signal' => 'onboarding_opened', 'count' => 40]);

        Artisan::call('cfb:telemetry');

        expect(Artisan::output())->toMatch('/onboarding_opened\s+40\s+since 2026-09-02/');
    });
});

describe('it reports and never gates', function () {
    it('exits zero even with something failing', function () {
        // cfb:doctor is the deploy gate. A snapshot command that fails a
        // pipeline because a request was slow is one somebody turns off.
        foreach (range(1, 12) as $i) {
            FeedRun::jobFailed(FetchGameSummary::class, 'boom');
        }

        expect(collect((new OpsReport)->checks())->firstWhere('key', 'failed_jobs')['status'])
            ->toBe(OpsReport::FAIL);

        $this->artisan('cfb:telemetry')->assertSuccessful();
    });

    it('reads at a terminal without --json', function () {
        $this->artisan('cfb:telemetry')
            ->expectsOutputToContain('Application')
            ->expectsOutputToContain('Data coverage')
            ->expectsOutputToContain('Funnel')
            ->assertSuccessful();
    });
});

describe('the board it feeds back', function () {
    it('names what is open, so the advisor updates rather than duplicates', function () {
        $open = WorkbookItem::factory()->create([
            'key' => 'picks-n-plus-one',
            'status' => WorkbookStatus::InProgress,
        ]);

        $workbook = telemetry()['workbook'];

        expect($workbook['open'])->toHaveCount(1)
            ->and($workbook['open'][0]['key'])->toBe('picks-n-plus-one')
            ->and($workbook['open'][0]['status'])->toBe('in_progress')
            // first_seen_at is the "how long has this been true" the advisor
            // should be raising severity on, not resetting.
            ->and($workbook['open'][0])->toHaveKey('first_seen_at');
    });

    it('names what a human already answered', function () {
        // This is what closes the loop. propose() refuses to reopen a
        // dismissed item whatever the routine sends, but a guard that
        // silently discards work is a routine that wastes its run
        // rediscovering it. Telling it up front is cheaper than refusing it.
        WorkbookItem::factory()->dismissed()->create(['key' => 'wont-fix']);
        WorkbookItem::factory()->create([
            'key' => 'already-fixed',
            'status' => WorkbookStatus::Done,
        ]);

        expect(telemetry()['workbook']['answered'])
            ->toBe(['already-fixed' => 'done', 'wont-fix' => 'dismissed']);
    });

    it('keeps the answered out of the open list', function () {
        WorkbookItem::factory()->dismissed()->create(['key' => 'wont-fix']);

        expect(telemetry()['workbook']['open'])->toBe([]);
    });
});
