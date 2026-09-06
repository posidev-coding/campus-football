<?php

use App\Actions\RecordActivity;
use App\Enums\ActivityKind;
use App\Models\ActivityEvent;
use App\Models\FeedRun;
use App\Models\User;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
 * Phase 3 of docs/plans/analytics.md: the command that finally pays for the
 * page views, and the ledger row that says it ran.
 *
 * Phase 2 holds the mapping itself (PageViewSensorTest, "the drain"). What is
 * asserted here is the part a schedule depends on — the feed run, the
 * `last_seen_at` write that must happen HERE and nowhere else, and a Redis
 * outage reaching the ledger rather than being swallowed the way the request
 * path swallows it.
 *
 * Real Redis on database 15, pinned in phpunit.xml, for the reason phase 2
 * gives: the guarantee is about a boundary, and a fake that only differs under
 * test is exactly where this class of bug hides.
 */

beforeEach(function () {
    Redis::connection('pulse')->flushdb();
});

/** Point the pulse connection at a closed port. The manager snapshots config. */
function breakDrainRedis(): void
{
    app()->singleton('redis', fn ($app) => new RedisManager($app, 'phpredis', [
        'client' => 'phpredis',
        'pulse' => ['host' => '127.0.0.1', 'port' => 65_000, 'database' => 15, 'timeout' => 0.2],
    ]));

    Redis::clearResolvedInstances();
}

describe('the drain command', function () {
    it('lands the buffered stream and empties it', function () {
        $this->travelTo('2026-09-06 01:30:00');

        $member = User::factory()->create();

        $this->actingAs($member)->get(route('scoreboard'))->assertOk();

        expect(app(RecordActivity::class)->pending())->toBe(1);

        $this->artisan('cfb:activity-drain')
            ->expectsOutputToContain('Wrote 1 activity events.')
            ->assertSuccessful();

        $row = ActivityEvent::sole();

        /*
         * The league day and hour, derived once by the drain. 01:30 UTC on
         * Sunday the 6th is 21:00 on SATURDAY the 5th in the league's own
         * timezone — the night game somebody was actually watching — and a
         * rollup that read the UTC date would file the whole west-coast
         * evening under the wrong day.
         */
        expect($row->day->toDateString())->toBe('2026-09-05')
            ->and($row->hour)->toBe(21)
            ->and($row->kind)->toBe(ActivityKind::PageView)
            ->and(app(RecordActivity::class)->pending())->toBe(0);
    });

    it('writes a feed run under the key the schedule panel reads', function () {
        // Untracked and "ran and found nothing" render identically without
        // this row, which is the whole reason a stalled drain is invisible.
        $this->get(route('scoreboard'))->assertOk();

        $this->artisan('cfb:activity-drain')->assertSuccessful();

        $run = FeedRun::latestFor('activity:drain');

        expect($run)->not->toBeNull()
            ->and($run->status)->toBe(FeedRun::COMPLETE)
            ->and($run->records)->toBe(1)
            // It spends no ESPN request, which is why it can ride a wake
            // rather than earning one.
            ->and($run->requests)->toBe(0);
    });

    it('records a failed run and exits non-zero when Redis is gone', function () {
        breakDrainRedis();

        /*
         * The opposite of the request path, deliberately. A page view swallows
         * every Throwable because measuring is not worth a 500; the drain
         * RETHROWS through trackRun, because a drain that could not reach
         * Redis and exited zero would leave the schedule panel green over a
         * dead pipeline.
         */
        $thrown = null;

        try {
            $this->artisan('cfb:activity-drain')->run();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull('A drain that cannot reach Redis must not exit zero.');

        expect(FeedRun::latestFor('activity:drain')->status)->toBe(FeedRun::FAILED);
    });

    it('writes one row when the same entry is drained by two runs', function () {
        $this->get(route('scoreboard'))->assertOk();

        $entries = (array) Redis::connection('pulse')->xRange(RecordActivity::STREAM, '-', '+');

        $this->artisan('cfb:activity-drain')->assertSuccessful();

        // The crash window: the insert landed, the XDEL did not, and the next
        // scheduled pass re-reads the same entry five minutes later.
        foreach ($entries as $id => $fields) {
            Redis::connection('pulse')->xAdd(RecordActivity::STREAM, (string) $id, (array) $fields);
        }

        $this->artisan('cfb:activity-drain')
            ->expectsOutputToContain('Wrote 0 activity events.')
            ->assertSuccessful();

        expect(ActivityEvent::count())->toBe(1);
    });

    it('keeps an unreported client hint null, and never a zero', function () {
        // A guest's first HTML response is sent before the cookie exists, so
        // this is the common case rather than an edge one. Null is "not
        // reported"; 0 and false are claims nobody made.
        $this->get(route('scoreboard'))->assertOk();

        $this->artisan('cfb:activity-drain')->assertSuccessful();

        expect(ActivityEvent::sole())
            ->viewport->toBeNull()
            ->standalone->toBeNull();
    });
});

describe('last seen', function () {
    it('is stamped by the drain and by nothing on the request path', function () {
        $this->travelTo('2026-09-06 01:30:00');

        $member = User::factory()->create(['last_seen_at' => null]);

        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (str_contains(strtolower($query->sql), 'update `users`')) {
                $writes++;
            }
        });

        $this->actingAs($member)->get(route('scoreboard'))->assertOk();

        // The request read a screen and wrote nothing about it. `last_seen_at`
        // on the request path would be one UPDATE per page view on the hottest
        // table in the app, which is the cost this whole pipeline exists to
        // avoid.
        expect($writes)->toBe(0)
            ->and($member->fresh()->last_seen_at)->toBeNull();

        $this->artisan('cfb:activity-drain')->assertSuccessful();

        expect($member->fresh()->last_seen_at->toDateTimeString())->toBe('2026-09-06 01:30:00');
    });

    it('never walks backwards when an entry is drained twice', function () {
        $member = User::factory()->create();

        $this->travelTo('2026-09-06 01:00:00');
        $this->actingAs($member)->get(route('scoreboard'))->assertOk();
        $early = (array) Redis::connection('pulse')->xRange(RecordActivity::STREAM, '-', '+');

        $this->artisan('cfb:activity-drain')->assertSuccessful();

        $this->travelTo('2026-09-06 02:00:00');
        $this->actingAs($member)->get(route('scoreboard'))->assertOk();
        $this->artisan('cfb:activity-drain')->assertSuccessful();

        // The crash window again, and this time it matters to a column
        // somebody reads: re-draining the 01:00 entry must not tell the admin
        // panel this person was last seen an hour before they were.
        foreach ($early as $id => $fields) {
            Redis::connection('pulse')->xAdd(RecordActivity::STREAM, (string) $id, (array) $fields);
        }

        $this->artisan('cfb:activity-drain')->assertSuccessful();

        expect($member->fresh()->last_seen_at->toDateTimeString())->toBe('2026-09-06 02:00:00');
    });

    it('leaves a guest with nothing to stamp', function () {
        // Nobody to stamp, and no row to invent for them.
        $this->get(route('scoreboard'))->assertOk();

        $this->artisan('cfb:activity-drain')->assertSuccessful();

        expect(ActivityEvent::sole()->user_id)->toBeNull()
            ->and(User::whereNotNull('last_seen_at')->count())->toBe(0);
    });
});
