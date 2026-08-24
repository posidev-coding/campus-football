<?php

namespace App\Console\Commands;

use App\Actions\RecordUxEvent;
use App\Console\Concerns\TracksFeedRun;
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
        $written = $this->trackRun('ux:rollup', null, fn (): int => $events->rollUp());

        $this->info("Persisted {$written} funnel counts.");

        return self::SUCCESS;
    }
}
