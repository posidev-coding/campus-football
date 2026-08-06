<?php

namespace App\Console\Commands;

use App\Console\Concerns\TracksFeedRun;
use App\Models\Season;
use App\Services\CfbCalendar;
use App\Services\Stats\AggregateAthleteStats;
use Illuminate\Console\Command;

/**
 * Roll box scores up into season totals.
 *
 * Pure arithmetic over data we already hold — no ESPN requests at all — so it
 * is cheap to re-run and safe to schedule.
 *
 * But it is NOT cheap in time: a full six-season pass is ~18 season/type
 * rounds over 305,000 box-score lines, and the seed run took half an hour on
 * production compute. A finished season's totals can never change, so the
 * SCHEDULE passes `--year=current` and only the season being played is
 * recomputed — the same reasoning that stopped SyncRankings re-reading
 * eighteen weeks of published polls to learn one.
 *
 * Bare `cfb:aggregate` still does every season, which is what a backfill
 * wants; it just is not what a nightly job should do.
 */
class AggregateStatsCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'cfb:aggregate
        {--year= : Season year, or current|results resolved at run time (defaults to every season with games)}
        {--type= : Season type, defaults to regular and postseason}';

    protected $description = 'Derive athlete season totals from stored box scores';

    public function handle(AggregateAthleteStats $aggregate): int
    {
        $years = $this->years();

        /*
         * FULL_SEASON last, so it folds a year that already has its parts.
         * It is what the leaderboards read: ESPN's headline numbers are
         * cumulative, and showing a regular-season figure beside them looks
         * like an error.
         */
        $types = $this->option('type') !== null
            ? [(int) $this->option('type')]
            : [Season::REGULAR, Season::POSTSEASON, AggregateAthleteStats::FULL_SEASON];

        $started = microtime(true);

        $total = $this->trackRun('aggregate', count($years) === 1 ? $years[0] : null, function () use ($years, $types, $aggregate): int {
            $total = 0;

            foreach ($years as $year) {
                foreach ($types as $type) {
                    $written = $aggregate->handle($year, $type);
                    $total += $written;

                    if ($written > 0) {
                        $this->line(sprintf(
                            '  <fg=green>✓</> %d %-12s <fg=gray>%d athlete-categories</>',
                            $year,
                            $type === AggregateAthleteStats::FULL_SEASON ? 'full season' : "type {$type}",
                            $written
                        ));
                    }
                }
            }

            return $total;
        });

        $this->newLine();
        $this->line(sprintf(
            '  %d rows in %.1fs  <fg=gray>peak %dMB, zero ESPN requests</>',
            $total,
            microtime(true) - $started,
            memory_get_peak_usage(true) / 1048576
        ));

        return self::SUCCESS;
    }

    /**
     * The seasons to recompute.
     *
     * `current` resolves through CfbCalendar at RUN time rather than being
     * baked into the schedule file — routes/console.php must not touch the
     * database while it loads, since it loads during every artisan command
     * including a deploy build with no tables yet.
     *
     * `resultsYear()` rather than `currentYear()`: this reads box scores, and
     * The schedule passes `results`, not `current`: this reads box scores, and
     * in August the season we are heading into has none. Aggregating it would
     * spend the whole pass writing nothing while the season that actually has
     * numbers went stale. In season the two tokens agree.
     *
     * @return list<int>
     */
    private function years(): array
    {
        if ($option = $this->option('year')) {
            return [app(CfbCalendar::class)->resolveYear($option)];
        }

        return Season::query()
            ->whereExists(fn ($q) => $q->selectRaw(1)->from('games')
                ->whereColumn('games.season_id', 'seasons.id')
                ->where('games.completed', true))
            ->distinct()
            ->orderBy('year')
            ->pluck('year')
            ->all();
    }
}
