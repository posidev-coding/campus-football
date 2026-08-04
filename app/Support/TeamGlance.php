<?php

namespace App\Support;

use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Models\Ranking;
use App\Models\Standing;
use App\Models\TeamSeason;
use App\Services\CfbCalendar;
use Illuminate\Support\Facades\Cache;

/**
 * The at-a-glance facts about every team, as flat cached maps.
 *
 * Search rows and the home page's team cards both need "11-2 (7-1)",
 * "3rd in SEC" and a poll rank for arbitrary teams, and neither can afford a
 * query per row. Each map here is ONE query over the whole league, cached as a
 * plain keyed array — never models, never Carbon; both round-trip through the
 * cache as __PHP_Incomplete_Class and fail on the second request, not the
 * first.
 *
 * "3rd in SEC" is computed, not read: no rank column exists on standings, and
 * ESPN's playoff_seed is sparse. Position is a team's index in its
 * conference's standings order, the same order the standings screen shows.
 *
 * Everything is memoized per-request on top of the cache, so a results list
 * can call these once per row without thinking about it.
 */
class TeamGlance
{
    private const CACHE_SECONDS = 900;

    /** @var array<string, array<int|string, mixed>> */
    private static array $memo = [];

    /** The season these maps describe — the latest one with games played. */
    public static function year(): int
    {
        return app(CfbCalendar::class)->resultsYear();
    }

    /**
     * team_id => ['overall' => '11-2', 'conference' => '7-1', 'streak' => 'W9'].
     *
     * @return array<int, array{overall: string, conference: string, streak: ?string}>
     */
    public static function records(?int $year = null): array
    {
        $year ??= self::year();

        return self::$memo["records:{$year}"] ??= Cache::remember(
            "glance:records:{$year}",
            self::CACHE_SECONDS,
            fn () => Standing::fromEspn()
                ->where('season_year', $year)
                ->get(['team_id', 'overall_wins', 'overall_losses', 'overall_ties', 'conf_wins', 'conf_losses', 'conf_ties', 'streak'])
                ->mapWithKeys(fn (Standing $s) => [$s->team_id => [
                    'overall' => $s->overallRecord(),
                    'conference' => $s->conferenceRecord(),
                    'streak' => $s->streak,
                ]])
                ->all(),
        );
    }

    /**
     * team_id => position within its conference standings (1-based).
     *
     * @return array<int, int>
     */
    public static function standingPositions(?int $year = null): array
    {
        $year ??= self::year();

        return self::$memo["positions:{$year}"] ??= Cache::remember(
            "glance:positions:{$year}",
            self::CACHE_SECONDS,
            function () use ($year) {
                $positions = [];

                Standing::fromEspn()
                    ->where('season_year', $year)
                    ->inStandingsOrder()
                    ->get(['team_id', 'conference_id'])
                    ->groupBy('conference_id')
                    ->each(function ($rows) use (&$positions) {
                        foreach ($rows->values() as $index => $standing) {
                            $positions[$standing->team_id] = $index + 1;
                        }
                    });

                return $positions;
            },
        );
    }

    /**
     * team_id => conference short_name ("SEC", "Big Ten").
     *
     * @return array<int, string>
     */
    public static function conferenceNames(?int $year = null): array
    {
        $year ??= self::year();

        return self::$memo["conferences:{$year}"] ??= Cache::remember(
            "glance:conferences:{$year}",
            self::CACHE_SECONDS,
            function () use ($year) {
                $names = Conference::query()->pluck('short_name', 'id');

                return TeamSeason::query()
                    ->where('season_year', $year)
                    ->whereNotNull('conference_id')
                    ->get(['team_id', 'conference_id'])
                    ->mapWithKeys(fn (TeamSeason $ts) => [$ts->team_id => $names[$ts->conference_id] ?? null])
                    ->filter()
                    ->all();
            },
        );
    }

    /**
     * team_id => classification ("FBS", "FCS", ...).
     *
     * @return array<int, string>
     */
    public static function classifications(?int $year = null): array
    {
        $year ??= self::year();

        return self::$memo["classifications:{$year}"] ??= Cache::remember(
            "glance:classifications:{$year}",
            self::CACHE_SECONDS,
            fn () => TeamSeason::query()
                ->where('season_year', $year)
                ->whereNotNull('classification')
                ->pluck('classification', 'team_id')
                ->all(),
        );
    }

    /**
     * team_id => rank in the newest release of the season's default poll —
     * CFP once it exists, AP until then, the same choice the rankings screen
     * defaults to.
     *
     * @return array<int, int>
     */
    public static function ranks(): array
    {
        return self::$memo['ranks'] ??= Cache::remember(
            'glance:ranks',
            self::CACHE_SECONDS,
            function () {
                $calendar = app(CfbCalendar::class);
                $poll = $calendar->defaultPoll();
                $year = $calendar->rankingsYear($poll->value);
                $weekId = $calendar->latestRankingRelease($year, $poll->value);

                if ($weekId === null) {
                    return [];
                }

                return Ranking::query()
                    ->where('week_id', $weekId)
                    ->where('poll', $poll->value)
                    ->pluck('rank', 'team_id')
                    ->all();
            },
        );
    }

    /**
     * conference_id => ['teams' => 16, 'classification' => 'FBS'].
     *
     * @return array<int, array{teams: int, classification: ?string}>
     */
    public static function conferenceSizes(?int $year = null): array
    {
        $year ??= self::year();

        return self::$memo["sizes:{$year}"] ??= Cache::remember(
            "glance:sizes:{$year}",
            self::CACHE_SECONDS,
            function () use ($year) {
                $counts = TeamSeason::query()
                    ->where('season_year', $year)
                    ->whereNotNull('conference_id')
                    ->selectRaw('conference_id, count(*) as members')
                    ->groupBy('conference_id')
                    ->pluck('members', 'conference_id');

                $classifications = ConferenceSeason::query()
                    ->where('season_year', $year)
                    ->pluck('classification', 'conference_id');

                return $counts
                    ->mapWithKeys(fn ($members, $conferenceId) => [(int) $conferenceId => [
                        'teams' => (int) $members,
                        'classification' => $classifications[$conferenceId] ?? null,
                    ]])
                    ->all();
            },
        );
    }

    /** Reset per-request memoization — tests re-seed between assertions. */
    public static function flush(): void
    {
        self::$memo = [];
    }
}
