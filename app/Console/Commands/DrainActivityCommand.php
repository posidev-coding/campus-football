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
        /*
         * The log stream rides the same wake and the SAME ledger row. Not a
         * second `trackRun` and not its own scheduled command: it is one
         * XRANGE against a stream that is empty in every deployment where
         * nobody added `pipelines` to LOG_STACK, and a second `feed_runs`
         * series for that would write a row every five minutes to say nothing
         * happened — with a `written` count that means log records rather than
         * events, in a series read as events.
         */
        $logs = 0;

        $written = $this->trackRun('activity:drain', null, function () use ($activity, &$logs): int {
            $events = $activity->drain();
            $logs = $activity->drainLogs();

            return $events;
        });

        $this->info("Wrote {$written} activity events.");

        // Only when there is something to say. The log stream is empty in
        // every deployment where nobody added `pipelines` to LOG_STACK, and a
        // "drained 0 log records" line every five minutes is noise in the one
        // place somebody reads to find out whether the pipeline is alive.
        if ($logs > 0) {
            $this->info("Drained {$logs} log records.");
        }

        return self::SUCCESS;
    }
}
