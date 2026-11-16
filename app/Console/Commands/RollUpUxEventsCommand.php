<?php

namespace App\Console\Commands;

use App\Actions\RecordUxEvent;
use App\Console\Concerns\TracksFeedRun;
use App\Enums\UxSignal;
use App\Models\ActivityEvent;
use App\Models\UxEvent;
use Illuminate\Console\Command;

/**
 * Move yesterday's funnel counts out of Redis and into `ux_events`.
 *
 * The counting happens on the request path in Redis because the flows being
 * measured are the two most latency-sensitive in the product; this is the
 * other half — one small write per (day, signal) once a day, off the path
 * entirely. Spends zero ESPN requests, so it rides an existing wake rather
 * than earning one: a scheduled task holds a scale-to-zero cluster up for the
 * whole sleep timeout, and a counter is not worth that.
 *
 * Idempotent by construction. Only FINISHED days are persisted, and the
 * (day, signal) unique index turns a re-run into a correction.
 */
class RollUpUxEventsCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'cfb:ux-rollup';

    protected $description = 'Persist finished days of funnel counters from Redis into ux_events';

    public function handle(RecordUxEvent $events): int
    {
        $written = $this->trackRun('ux:rollup', null, fn (): int => $events->rollUp() + $this->stateYesterday());

        $this->info("Persisted {$written} funnel counts.");

        return self::SUCCESS;
    }

    /**
     * Give the day that just finished a row for every signal, zero included —
     * but only when that day was demonstrably being counted.
     *
     * `rollUp()` walks the days Redis has a hash for, and a day only gets one
     * when somebody fires a signal. A league day on which nobody did — the
     * Sunday and Monday after a slate, 2026-11-08 and 2026-11-09 — was never
     * visited, so it got no row at all, and `funnel_since` slid forward past
     * it as if the counter had shipped the day after.
     *
     * An absent hash is NOT proof that nothing fired, though. The recorder
     * swallows every write failure (a counter is never worth a 500), so a
     * day-long stall on the `pulse` connection leaves exactly the same empty
     * Redis as a quiet day, and by 04:55 the store can be back and readable.
     * {@see wasCounting()} is the evidence that tells them apart; without it
     * the day is left absent, and the snapshot's `funnel_missing_days` says
     * so rather than this writing ten invented zeros.
     *
     * Runs only AFTER `rollUp()` has read Redis without throwing, so an
     * unreachable store records a failed run rather than a day of zeros. Only
     * yesterday, never an older silent day. `firstOrCreate`, so a count
     * `rollUp()` just persisted is never undone.
     *
     * @return int the number of zero rows written
     */
    private function stateYesterday(): int
    {
        $yesterday = now()->timezone(config('cfb.timezone'))->subDay()->toDateString();

        if (! $this->wasCounting($yesterday)) {
            return 0;
        }

        $written = 0;

        foreach (UxSignal::cases() as $signal) {
            $row = UxEvent::firstOrCreate(
                ['day' => $yesterday, 'signal' => $signal->value],
                ['count' => 0],
            );

            $written += $row->wasRecentlyCreated ? 1 : 0;
        }

        return $written;
    }

    /**
     * Whether the `pulse` Redis was taking request-path writes on a league day.
     *
     * The clickstream rides the same connection on the same requests:
     * `RecordActivity` XADDs every page view to it, and the drain has moved
     * the whole of yesterday into `activity_events` long before 04:55 (every
     * five minutes until 03:00 in season, at midnight out of it). A stalled
     * Redis drops those writes as silently as it drops the funnel's, so a day
     * with no clickstream row at all is a day nothing on that connection can
     * vouch for. One row is enough: the claim is "this store was writable",
     * and a page view is a write that happened.
     */
    private function wasCounting(string $day): bool
    {
        return ActivityEvent::query()->where('day', $day)->exists();
    }
}
