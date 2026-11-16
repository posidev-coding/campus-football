<?php

namespace App\Console\Commands;

use App\Actions\RecordUxEvent;
use App\Console\Concerns\TracksFeedRun;
use App\Enums\UxSignal;
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
     * Give the day that just finished a row for every signal, zero included.
     *
     * `rollUp()` walks the days Redis has a hash for, and a day only gets one
     * when somebody fires a signal. A league day on which nobody did — the
     * Sunday and Monday after a slate, 2026-11-08 and 2026-11-09 — was never
     * visited, so it got no row at all, and `funnel_since` slid forward past
     * it as if the counter had shipped the day after. Nothing fired on a day
     * the deployed code was counting is a measured zero, and this run is the
     * one that measured it.
     *
     * Runs only AFTER `rollUp()` has read Redis without throwing, so an
     * unreachable store records a failed run rather than a day of zeros. Only
     * yesterday, never an older silent day: a zero written now for a day no
     * run was there to read would be an invented measurement, and the
     * snapshot's `funnel_missing_days` says such a day is absent instead.
     * `firstOrCreate`, so a count `rollUp()` just persisted is never undone.
     *
     * @return int the number of zero rows written
     */
    private function stateYesterday(): int
    {
        $yesterday = now()->timezone(config('cfb.timezone'))->subDay()->toDateString();

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
}
