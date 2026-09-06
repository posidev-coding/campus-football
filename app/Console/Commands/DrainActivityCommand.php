<?php

namespace App\Console\Commands;

use App\Actions\RecordActivity;
use App\Console\Concerns\TracksFeedRun;
use Illuminate\Console\Command;

/**
 * Land the buffered clickstream in MySQL.
 *
 * The sensor writes a Redis stream on the request path and nothing else; this
 * is the other half, out of band, where the cost of a page view is finally
 * paid. `XRANGE` → `insertOrIgnore` → `XDEL`, which the unique `stream_id`
 * makes exactly-once across a crash — see {@see RecordActivity::drain()}.
 *
 * NOT A DAEMON, on purpose. `pulse:work` is already a Cloud daemon configured
 * outside this repository, and a second one is a process `SyncSchedule`
 * cannot see and the ledger cannot report overdue: it would fail exactly the
 * way a stalled drain fails, silently, looking like a quiet week. A scheduled
 * command writes a `feed_runs` row every pass, so "did it run" has an answer.
 *
 * Spends no ESPN request, so it rides wakes that already exist rather than
 * earning its own — every five minutes inside the in-season window the live
 * tier and the reminders already keep the cluster up for, and six-hourly off
 * season on the news sync's wake. `MAXLEN` 200,000 covers six off-season
 * hours many times over.
 */
class DrainActivityCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'cfb:activity-drain';

    protected $description = 'Drain buffered activity events from Redis into activity_events';

    public function handle(RecordActivity $activity): int
    {
        // A Redis failure inside drain() is RETHROWN by trackRun after the
        // ledger row is marked failed. Bookkeeping, never a rescue: a drain
        // that could not reach Redis must not exit zero, or the schedule
        // panel reads a dead pipeline as a quiet one.
        $written = $this->trackRun('activity:drain', null, fn (): int => $activity->drain());

        $this->info("Wrote {$written} activity events.");

        return self::SUCCESS;
    }
}
