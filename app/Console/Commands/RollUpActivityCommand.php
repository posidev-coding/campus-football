<?php

namespace App\Console\Commands;

use App\Actions\RecordActivity;
use App\Console\Concerns\TracksFeedRun;
use App\Support\ActivityRollup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Fold a league day of clickstream into `page_views_daily` and `user_days`.
 *
 * Yesterday by default, at 04:56 — one minute behind `cfb:ux-rollup`, on the
 * wake the prunes already pay for. `--today` is the same code path over the
 * day in progress, so a Saturday dashboard is minutes behind rather than a
 * day; `--day=` is the repair, and it is safe to run over and over because
 * every write is an upsert on the table's own unique key.
 *
 * IT DRAINS FIRST. The rollup reads `activity_events`, and anything still
 * buffered in Redis when it runs would simply be missing from the day — a
 * hole no later pass would notice, since the day would already have rows in
 * it. Draining first costs nothing when the five-minute drain is keeping up
 * and is the difference between a partial day and a complete one when it is
 * not.
 */
class RollUpActivityCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'cfb:activity-rollup
                            {--day= : The league day to recompute, Y-m-d}
                            {--today : Roll today so far, rather than yesterday}';

    protected $description = 'Roll a league day of activity into page_views_daily and user_days';

    public function handle(RecordActivity $activity, ActivityRollup $rollup): int
    {
        $day = $this->day();

        $counts = ['page_views' => 0, 'user_days' => 0];

        // By REFERENCE. A closure captures by value, so the counts written
        // inside this one would otherwise be written to a copy and the line
        // below would print two zeroes over a rollup that worked.
        $rows = $this->trackRun('activity:rollup', null, function () use ($activity, $rollup, $day, &$counts): int {
            $activity->drain();

            $counts = $rollup->day($day);

            return $counts['page_views'] + $counts['user_days'];
        });

        $this->info(sprintf(
            'Rolled %s: %d rows — %d page-view cells, %d user days.',
            $day->toDateString(),
            $rows,
            $counts['page_views'],
            $counts['user_days'],
        ));

        return self::SUCCESS;
    }

    /**
     * Which league day to roll.
     *
     * Yesterday rather than today by default, because the scheduled pass runs
     * before dawn and the only day it can state COMPLETELY is the one that
     * has finished. `--today` says the caller wants the partial on purpose.
     */
    private function day(): CarbonImmutable
    {
        $today = CarbonImmutable::now(config('cfb.timezone'))->startOfDay();

        $day = (string) $this->option('day');

        if ($day !== '') {
            return CarbonImmutable::parse($day, config('cfb.timezone'))->startOfDay();
        }

        return $this->option('today') ? $today : $today->subDay();
    }
}
