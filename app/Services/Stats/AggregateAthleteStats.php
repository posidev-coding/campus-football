<?php

namespace App\Services\Stats;

use App\Models\AthleteGameStat;
use App\Models\AthleteSeasonStat;
use App\Models\Game;
use App\Models\Season;
use App\Support\Stats\StatCatalog;
use Illuminate\Support\Collection;

/**
 * Season totals, derived from the box scores we already hold.
 *
 * Costs ZERO ESPN requests. Every input is an `athlete_game_stats` row that the
 * game summary sync already stored — 305,000 of them across five seasons — so
 * this is arithmetic, not a feed.
 *
 * It exists because ESPN's national leaders endpoint is the wrong source for a
 * scoped leaderboard. That feed spans every division, and only about half its
 * top 100 is FBS, so filtering to FBS leaves gaps in the ranking and truncates
 * the list. Narrower still and it collapses outright: the MAC had FOUR players
 * in the national top 100 for passing yards. Deriving our own totals means a
 * conference leaderboard ranks that conference's players 1..N instead of
 * showing whichever four happened to crack a national list.
 *
 * Validated against ESPN before being trusted: summing Drew Mestemaker's 2025
 * regular-season passing yards from our box scores gives 4129, which is exactly
 * what ESPN's own leaderboard reports.
 */
class AggregateAthleteStats
{
    /**
     * Sentinel season type meaning "the whole year, bowls included".
     *
     * ESPN's own headline leaders are cumulative — its stats page reports Drew
     * Mestemaker at 4,379 passing yards, which is his 4,129 regular season plus
     * 250 in the playoff. A leaderboard that quietly showed 4,129 would look
     * wrong to anyone comparing.
     *
     * Stored as its own row rather than summed at read time because rate stats
     * cannot be added: combining two seasons' yards-per-attempt means summing
     * the components and dividing again, which is exactly what this class
     * already does once.
     */
    public const FULL_SEASON = 0;

    /**
     * Rebuild one season type's totals.
     *
     * Pass FULL_SEASON to fold every type of the year into a single row.
     *
     * Returns the number of athlete-category rows written.
     */
    public function handle(int $year, int $seasonType = Season::REGULAR): int
    {
        $seasons = Season::where('year', $year)
            ->when($seasonType !== self::FULL_SEASON, fn ($q) => $q->where('type', $seasonType))
            ->pluck('id');

        $gameIds = Game::whereIn('season_id', $seasons)->pluck('id');

        if ($gameIds->isEmpty()) {
            return 0;
        }

        $totals = $this->fold($gameIds);

        return $this->store($totals, $year, $seasonType);
    }

    /**
     * Fold every box score line into per-athlete, per-category totals.
     *
     * Streamed with chunkById rather than loaded whole: a season is ~60,000
     * rows and this is the same class of work that exhausted memory when the
     * summary sync ran in one process. The accumulator itself stays small —
     * roughly 15,000 athletes by a handful of categories.
     *
     * @param  Collection<int, int>  $gameIds
     * @return array<string, array<string, mixed>>
     */
    private function fold($gameIds): array
    {
        $totals = [];

        AthleteGameStat::query()
            ->whereIn('game_id', $gameIds)
            ->select(['id', 'athlete_id', 'team_id', 'category', 'stats'])
            ->chunkById(2000, function ($rows) use (&$totals) {
                foreach ($rows as $row) {
                    $key = $row->athlete_id.':'.$row->category;

                    $totals[$key] ??= [
                        'athlete_id' => $row->athlete_id,
                        'category' => $row->category,
                        'team_id' => $row->team_id,
                        'games' => 0,
                        'stats' => [],
                    ];

                    $totals[$key]['games']++;

                    // Last team wins: a transfer's later games are the ones a
                    // reader expects to see them listed under.
                    if ($row->team_id !== null) {
                        $totals[$key]['team_id'] = $row->team_id;
                    }

                    $this->accumulate($totals[$key]['stats'], $row->stats ?? []);
                }
            });

        return $totals;
    }

    /**
     * Add one game's line into a running total.
     *
     * @param  array<string, float>  $carry
     * @param  array<string, mixed>  $stats
     */
    private function accumulate(array &$carry, array $stats): void
    {
        foreach ($stats as $name => $value) {
            // "25/42" carries two numbers. Split so both can be summed and so
            // the rate stats below have a denominator to divide by.
            if (isset(StatCatalog::COMPOUND_STATS[$name])) {
                [$left, $right] = StatCatalog::COMPOUND_STATS[$name];
                $parts = explode('/', (string) $value);

                $carry[$left] = ($carry[$left] ?? 0) + $this->number($parts[0] ?? 0);
                $carry[$right] = ($carry[$right] ?? 0) + $this->number($parts[1] ?? 0);

                continue;
            }

            // Recomputed from components afterwards; a per-game rate cannot be
            // summed and averaging averages weights a 1-carry game like a
            // 30-carry one.
            if (isset(StatCatalog::RATE_STATS[$name]) || in_array($name, StatCatalog::UNDERIVABLE, true)) {
                continue;
            }

            $number = $this->number($value);

            if (in_array($name, StatCatalog::MAX_STATS, true)) {
                // A season's longest run is the longest single run, not the
                // total of every game's longest.
                $carry[$name] = max($carry[$name] ?? 0, $number);

                continue;
            }

            $carry[$name] = ($carry[$name] ?? 0) + $number;
        }
    }

    /**
     * Write each chunk as ONE upsert, with NO transaction around it.
     *
     * Deliberate on both counts. Laravel's reconnect-and-retry on a lost
     * connection only runs while no transaction is open, and this command's
     * half-hour seed pass is the widest exposure window of anything scheduled
     * — wrapping the write re-disables the one recovery the platform gives us.
     * The replay is safe because a chunk is a single statement keyed by
     * `athlete_season_stats_unique`, and every value in it was computed in
     * memory before the first write: running it twice writes the same rows.
     *
     * A failure that survives the retry propagates — the command's ledger row
     * must say the run died rather than present a partial total as complete.
     *
     * @param  array<string, array<string, mixed>>  $totals
     */
    private function store(array $totals, int $year, int $seasonType): int
    {
        $written = 0;
        $now = now();

        foreach (array_chunk($totals, 500) as $chunk) {
            $rows = [];

            foreach ($chunk as $entry) {
                $stats = $this->withRates($entry['stats']);
                $stats['gamesPlayed'] = $entry['games'];

                $rows[] = [
                    'athlete_id' => $entry['athlete_id'],
                    'season_year' => $year,
                    'season_type' => $seasonType,
                    'category' => $entry['category'],
                    'team_id' => $entry['team_id'],
                    // upsert() bypasses the model's casts, so the JSON column
                    // is encoded here.
                    'stats' => json_encode($stats),
                    'display_stats' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            AthleteSeasonStat::upsert(
                $rows,
                ['athlete_id', 'season_year', 'season_type', 'category'],
                ['team_id', 'stats', 'display_stats', 'updated_at'],
            );

            $written += count($rows);
        }

        return $written;
    }

    /**
     * Recompute rate stats from the summed components.
     *
     * @param  array<string, float>  $stats
     * @return array<string, float>
     */
    private function withRates(array $stats): array
    {
        foreach (StatCatalog::RATE_STATS as $name => [$numerator, $denominator, $decimals]) {
            $bottom = $stats[$denominator] ?? 0;

            if ($bottom > 0) {
                $top = $stats[$numerator] ?? 0;

                $stats[$name] = round(
                    $name === 'fieldGoalPct' || $name === 'completionPct'
                        ? $top / $bottom * 100
                        : $top / $bottom,
                    $decimals
                );
            }
        }

        return $stats;
    }

    /**
     * ESPN ships these as display strings — "1,284", "14.5", "--" for absent.
     */
    private function number(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $cleaned = str_replace([',', ' '], '', (string) $value);

        return is_numeric($cleaned) ? (float) $cleaned : 0.0;
    }
}
