<?php

use App\Actions\RecordUxEvent;
use App\Enums\UxSignal;
use App\Jobs\FetchGameSummary;
use App\Models\ClientError;
use App\Models\FeedRun;
use App\Models\User;
use App\Models\UxEvent;
use App\Support\CoverageReport;
use App\Support\OpsReport;
use App\Support\PickemPreflight;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
 * The third report in the house shape: is the APPLICATION behaving, beside
 * CoverageReport (is the data there) and PickemPreflight (is the product
 * ready). It reads only what Phase 1's sensors record, and it carries counts
 * and never a user — the payload goes to a routine with no database access.
 */

beforeEach(function () {
    Redis::connection('pulse')->flushdb();
    $this->travelTo('2026-09-05 18:00:00');
});

/** Write one Pulse entry as the recorders would. */
function pulseEntry(string $type, string $key, int $value, int $minutesAgo = 5): void
{
    DB::table('pulse_entries')->insert([
        'timestamp' => now()->subMinutes($minutesAgo)->getTimestamp(),
        'type' => $type,
        'key' => $key,
        'value' => $value,
    ]);
}

/**
 * An exception, recorded the way Pulse actually records one.
 *
 * `value` holds the OCCURRENCE TIMESTAMP, not a duration
 * (`Recorders/Exceptions.php`, `value: $timestamp`). Writing `value: 1` here —
 * which this file used to do — makes every exception tie, so the row's
 * ordering is whatever MySQL feels like and the test proves nothing about it.
 */
function exceptionEntry(string $key, int $minutesAgo = 5): void
{
    $thrownAt = now()->subMinutes($minutesAgo)->getTimestamp();

    DB::table('pulse_entries')->insert([
        'timestamp' => $thrownAt,
        'type' => 'exception',
        'key' => $key,
        'value' => $thrownAt,
    ]);
}

describe('the shape', function () {
    it('agrees with the other two reports, row for row', function () {
        // Same keys on purpose: one terminal renderer prints all three, and a
        // future admin page can show them side by side.
        $keys = array_keys((new OpsReport)->checks()[0]);

        expect($keys)->toBe(['key', 'label', 'status', 'detail', 'remedy'])
            ->and($keys)->toBe(array_keys(app(PickemPreflight::class)->checks()[0]));

        // CoverageReport carries expected/actual as well; the five above are
        // the shared core.
        expect(array_keys(app(CoverageReport::class)->checks()[0]))->toContain(...$keys);
    });

    it('says nothing is wrong on a quiet, healthy install', function () {
        $report = new OpsReport;

        expect($report->failing())->toBe(0)
            ->and(collect($report->checks())->pluck('status')->unique()->all())->toBe([OpsReport::OK]);
    });

    it('carries no user identity anywhere in the payload', function () {
        // This is handed to a Claude Code routine. A report that carries
        // identity is a report that cannot be handed to anything.
        // A distinctive id, so the assertion is about identity leaking and
        // not about the digit 1 appearing in a count.
        $user = User::factory()->create(['id' => 987654, 'email' => 'dolly@example.test']);
        ClientError::create([
            'fingerprint' => str_repeat('a', 40), 'kind' => 'error',
            'message' => 'boom', 'user_id' => $user->id, 'reports' => 1,
        ]);

        $payload = json_encode((new OpsReport)->checks());

        expect($payload)->not->toContain('dolly@example.test')
            ->and($payload)->not->toContain('user_id')
            ->and($payload)->not->toContain('987654');

        // Every row is exactly the five shared keys — nothing can be added
        // without this failing.
        foreach ((new OpsReport)->checks() as $row) {
            expect(array_keys($row))->toBe(['key', 'label', 'status', 'detail', 'remedy']);
        }
    });
});

describe('the monitor watching itself', function () {
    it('catches a stalled drain, which otherwise looks exactly like no traffic', function () {
        // The one row here that is about the sensors rather than the app: if
        // pulse:work is not running, every Pulse-fed row below silently reads
        // "nothing happened".
        foreach (range(1, OpsReport::INGEST_FAIL) as $i) {
            Redis::connection('pulse')->xadd('laravel:pulse:ingest', '*', ['data' => 'x']);
        }

        $row = collect((new OpsReport)->checks())->firstWhere('key', 'pulse_ingest');

        expect($row['status'])->toBe(OpsReport::FAIL)
            ->and($row['remedy'])->toContain('pulse:work');
    })->skipOnWindows();

    it('reports the buffer as healthy when it is empty', function () {
        $row = collect((new OpsReport)->checks())->firstWhere('key', 'pulse_ingest');

        expect($row['status'])->toBe(OpsReport::OK)
            ->and($row['detail'])->toContain('keeping up');
    });
});

describe('what it reads', function () {
    it('counts exceptions and names the LATEST one, not the worst', function () {
        /*
         * `orderByDesc('value')` picks the slowest entry for the four slow_*
         * types, because `value` is a duration there. For `exception` Pulse
         * writes the occurrence timestamp instead, so the same ordering means
         * MOST RECENT — and the row has to say the word it actually means.
         *
         * The two entries are deliberately minutes apart, so "latest" is a
         * claim the fixture can falsify rather than a tie.
         */
        exceptionEntry('["TypeError","app\/Support\/Other.php:9"]', minutesAgo: 45);
        exceptionEntry('["RuntimeException","app\/Jobs\/Thing.php:20"]', minutesAgo: 2);

        $row = collect((new OpsReport)->checks())->firstWhere('key', 'exceptions');

        expect($row['status'])->toBe(OpsReport::WARN)
            ->and($row['detail'])->toContain('2 thrown')
            ->and($row['detail'])->toContain('latest: ')
            ->and($row['detail'])->toContain('RuntimeException')
            // ...and not the older one, which is what makes the ordering a
            // measured claim rather than a coin flip.
            ->and($row['detail'])->not->toContain('TypeError');
    });

    it('still says "worst" where value really is a duration', function () {
        // The four slow_* types order by a real measurement, so the word is
        // honest there and must not be swept up in the rename.
        pulseEntry('slow_request', '["GET","\/picks"]', 1_200);

        $row = collect((new OpsReport)->checks())->firstWhere('key', 'slow_requests');

        expect($row['detail'])->toContain('worst: ')
            ->and($row['detail'])->toContain('GET /picks');
    });

    it('surfaces the slow query, which no test can ever catch', function () {
        // preventLazyLoading's per-instance flag is false under test, so a
        // missing eager load resolves silently in CI and N+1s in production.
        pulseEntry('slow_query', '["select * from `games`","app\/Support\/Rail.php:40"]', 2_400);

        $row = collect((new OpsReport)->checks())->firstWhere('key', 'slow_queries');

        expect($row['status'])->toBe(OpsReport::WARN)
            ->and($row['detail'])->toContain('games')
            ->and($row['remedy'])->toContain('eager loads');
    });

    it('ignores anything outside the window', function () {
        exceptionEntry('["OldError","app\/Old.php:1"]', minutesAgo: 60 * (OpsReport::HOURS + 1));

        expect(collect((new OpsReport)->checks())->firstWhere('key', 'exceptions')['status'])
            ->toBe(OpsReport::OK);
    });

    it('reads the job failures the app can actually see', function () {
        // Cloud's managed queues hide failed_jobs, so these rows are the only
        // record there is.
        FeedRun::jobFailed(FetchGameSummary::class, 'ESPN returned 403');

        $row = collect((new OpsReport)->checks())->firstWhere('key', 'failed_jobs');

        expect($row['status'])->toBe(OpsReport::WARN)
            ->and($row['detail'])->toBe('1 died');
    });

    it('separates distinct browser errors from how loud they were', function () {
        ClientError::create([
            'fingerprint' => str_repeat('a', 40), 'kind' => 'error',
            'message' => "Cannot read properties of undefined (reading 'games')", 'reports' => 4_000,
        ]);
        ClientError::create([
            'fingerprint' => str_repeat('b', 40), 'kind' => 'unhandledrejection',
            'message' => 'Failed to fetch', 'reports' => 2,
        ]);

        $row = collect((new OpsReport)->checks())->firstWhere('key', 'client_errors');

        expect($row['detail'])->toContain('2 distinct, 4002 reports')
            ->and($row['detail'])->toContain('Cannot read properties');
    });
});

describe('the derived pick-through rate', function () {
    it('stays quiet below the sample floor', function () {
        app(RecordUxEvent::class)->handle(UxSignal::SlateEntered);

        $row = collect((new OpsReport)->checks())->firstWhere('key', 'pick_through');

        expect($row['status'])->toBe(OpsReport::OK)
            ->and($row['detail'])->toContain('too few');
    });

    it('warns when more than half open a slate and leave', function () {
        foreach (range(1, 40) as $i) {
            app(RecordUxEvent::class)->handle(UxSignal::SlateEntered);
        }
        foreach (range(1, 10) as $i) {
            app(RecordUxEvent::class)->handle(UxSignal::FirstPickMade);
        }

        $row = collect((new OpsReport)->checks())->firstWhere('key', 'pick_through');

        expect($row['status'])->toBe(OpsReport::WARN)
            ->and($row['detail'])->toContain('25% of slate opens');
    });

    it('counts today, which the nightly rollup has not persisted yet', function () {
        // Blind to the last few hours is blind during exactly the incident
        // somebody is reading this report to understand.
        UxEvent::create(['day' => '2026-09-04', 'signal' => 'slate_entered', 'count' => 30]);
        UxEvent::create(['day' => '2026-09-04', 'signal' => 'first_pick_made', 'count' => 30]);

        foreach (range(1, 10) as $i) {
            app(RecordUxEvent::class)->handle(UxSignal::SlateEntered);
        }

        $row = collect((new OpsReport)->checks())->firstWhere('key', 'pick_through');

        expect($row['detail'])->toContain('30 of 40');
    });
});
