<?php

namespace App\Support\Stats;

use App\Models\AthleteSeasonStat;
use App\Models\Season;
use App\Models\TeamSeasonStat;
use App\Services\Stats\AggregateAthleteStats;
use App\Support\Scope;
use Illuminate\Support\Facades\Cache;

/**
 * Ranks players and teams WITHIN a scope, from our own derived totals.
 *
 * The point of ranking locally rather than reading ESPN's national list: that
 * list spans every division and only about half its top 100 is FBS, so any
 * narrower scope was showing whichever few players happened to crack a national
 * board. The MAC had four. Ranking our own aggregates gives 43.
 */
class LeaderQuery
{
    private const CACHE_TTL = 900;

    /**
     * One leaderboard.
     *
     * @param  array<string, mixed>  $board  a StatCatalog entry
     * @return list<array{rank:int, athlete_id:int, team_id:?int, value:float, display:string}>
     */
    public static function players(array $board, int $year, string $scope, int $limit = 10): array
    {
        $key = "leaders:{$year}:{$scope}:{$board['category']}:{$board['stat']}:{$limit}";

        return Cache::remember($key, self::CACHE_TTL, function () use ($board, $year, $scope, $limit) {
            $teamIds = Scope::teamIds($scope, $year);

            $rows = AthleteSeasonStat::query()
                ->where('season_year', $year)
                // The whole year, bowls included — ESPN's headline leaders are
                // cumulative, and a regular-season figure beside them reads as
                // an error rather than a different definition.
                ->where('season_type', AggregateAthleteStats::FULL_SEASON)
                ->where('category', $board['category'])
                ->when($teamIds !== null, fn ($q) => $q->whereIn('team_id', $teamIds))
                ->get(['athlete_id', 'team_id', 'stats']);

            [$minStat, $minValue] = $board['min'] ?? [null, 0];

            return $rows
                ->map(fn (AthleteSeasonStat $r) => [
                    'athlete_id' => $r->athlete_id,
                    'team_id' => $r->team_id,
                    'value' => (float) ($r->stats[$board['stat']] ?? 0),
                    'qualifier' => $minStat ? (float) ($r->stats[$minStat] ?? 0) : null,
                ])
                ->filter(fn (array $r) => $r['value'] > 0)
                // A rate leaderboard without a floor is won by whoever attempted
                // once — a 1-for-1 passer at 20.0 yards per attempt.
                ->filter(fn (array $r) => $minStat === null || $r['qualifier'] >= $minValue)
                ->sortByDesc('value')
                ->take($limit)
                ->values()
                ->map(fn (array $r, int $i) => [
                    'rank' => $i + 1,
                    'athlete_id' => $r['athlete_id'],
                    'team_id' => $r['team_id'],
                    'value' => $r['value'],
                    'display' => self::format($r['value'], $board['decimals'] ?? 0),
                ])
                ->all();
        });
    }

    /**
     * One team leaderboard, ranked within the scope.
     *
     * ESPN publishes a national rank on every team stat, but that rank is
     * national — useless the moment the reader picks a conference. Ranking the
     * scope locally means the SEC's first row says 1, and ESPN's own national
     * rank is still carried alongside for context.
     *
     * @param  array<string, mixed>  $board
     * @return list<array{rank:int, team_id:int, value:float, display:string, national:?int}>
     */
    public static function teams(array $board, int $year, string $scope, int $limit = 10): array
    {
        $key = "teamstats:{$year}:{$scope}:{$board['category']}:{$board['stat']}:{$limit}";

        return Cache::remember($key, self::CACHE_TTL, function () use ($board, $year, $scope, $limit) {
            $teamIds = Scope::teamIds($scope, $year);

            $rows = TeamSeasonStat::query()
                ->where('season_year', $year)
                // Team stats come from ESPN per season type, so regular season
                // is the only complete series we hold for them.
                ->where('season_type', Season::REGULAR)
                ->where('category', $board['category'])
                ->when($teamIds !== null, fn ($q) => $q->whereIn('team_id', $teamIds))
                ->get(['team_id', 'stats']);

            return $rows
                ->map(function (TeamSeasonStat $r) use ($board) {
                    $stat = $r->stat($board['stat']);

                    return [
                        'team_id' => $r->team_id,
                        'value' => $stat['value'] ?? 0.0,
                        'display' => $stat['display'],
                        'national' => $stat['rank'],
                    ];
                })
                ->filter(fn (array $r) => $r['display'] !== null)
                ->sortByDesc('value')
                ->take($limit)
                ->values()
                ->map(fn (array $r, int $i) => [
                    'rank' => $i + 1,
                    'team_id' => $r['team_id'],
                    'value' => $r['value'],
                    'display' => $r['display'],
                    'national' => $r['national'],
                ])
                ->all();
        });
    }

    private static function format(float $value, int $decimals): string
    {
        return $decimals > 0
            ? number_format($value, $decimals)
            : number_format($value);
    }
}
