<?php

namespace App\Console\Commands;

use App\Models\Season;
use App\Services\Stats\AggregateAthleteStats;
use Illuminate\Console\Command;

/**
 * Roll box scores up into season totals.
 *
 * Pure arithmetic over data we already hold — no ESPN requests at all — so it
 * is cheap to re-run and safe to schedule.
 */
class AggregateStatsCommand extends Command
{
    protected $signature = 'cfb:aggregate
        {--year= : Season year (defaults to every season with games)}
        {--type= : Season type, defaults to regular and postseason}';

    protected $description = 'Derive athlete season totals from stored box scores';

    public function handle(AggregateAthleteStats $aggregate): int
    {
        $years = $this->option('year')
            ? [(int) $this->option('year')]
            : Season::query()
                ->whereExists(fn ($q) => $q->selectRaw(1)->from('games')
                    ->whereColumn('games.season_id', 'seasons.id')
                    ->where('games.completed', true))
                ->distinct()
                ->orderBy('year')
                ->pluck('year')
                ->all();

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

        $this->newLine();
        $this->line(sprintf(
            '  %d rows in %.1fs  <fg=gray>peak %dMB, zero ESPN requests</>',
            $total,
            microtime(true) - $started,
            memory_get_peak_usage(true) / 1048576
        ));

        return self::SUCCESS;
    }
}
