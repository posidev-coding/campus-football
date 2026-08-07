<?php

namespace App\Support;

use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Models\Ranking;
use App\Models\Standing;
use App\Models\Team;
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
     * A team holds several ESPN standings rows (see `inOwnConference`) and
     * keying by team_id collapses them, so the last one read wins. Left that
     * way on purpose: checked across every stored season, the duplicates never
     * disagree on a single record or streak, and unlike a position a record
     * needs no conference to mean anything — so there is no wrong answer
     * available here to be protected from.
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
     * team_id => position within its own conference's standings (1-based).
     *
     * The rank and the LABEL beside it must come from one grouping. Every
     * caller writes "6th in SEC" by pairing this map with a conference name
     * read from `team_seasons`, so a position counted over any other grouping
     * is a number attached to the wrong noun. `inOwnConference()` is what
     * makes the two agree by construction rather than by coincidence.
     *
     * It was counted over `standings.conference_id`, which is the group ESPN
     * was ASKED for and not the team's conference, so a team appeared in
     * several groups and whichever came last silently won:
     *
     *   2026 Tennessee   130th of the 138-team "FBS" group, labelled SEC
     *   2025 Sun Belt    every team's EAST/WEST position, so a 14-team
     *                    conference read two 1sts, two 2nds, two 3rds...
     *
     * The second is the one worth remembering: it was wrong in a fully played
     * season, on a real conference, and looked entirely plausible.
     *
     * @return array<int, int>
     */
    public static function standingPositions(?int $year = null): array
    {
        $year ??= self::year();

        /*
         * Key versioned: the shape is the same but the values are not, and a
         * live entry would keep serving "130th in SEC" past the deploy.
         *
         * Cache::remember rather than Remember::filled, deliberately. An empty
         * map here is the ANSWER — a league where nothing has kicked off has
         * no standings, and it stays that way for months — not a backfill that
         * has yet to land, which is the only thing filled() exists for.
         */
        return self::$memo["positions:{$year}"] ??= Cache::remember(
            "glance:positions:v2:{$year}",
            self::CACHE_SECONDS,
            function () use ($year) {
                $positions = [];

                Standing::fromEspn()
                    ->where('season_year', $year)
                    ->inOwnConference($year)
                    ->inStandingsOrder()
                    ->get(['team_id', 'conference_id', 'overall_wins', 'overall_losses', 'overall_ties'])
                    ->groupBy('conference_id')
                    ->each(function ($rows) use (&$positions) {
                        /*
                         * A conference nobody has played in yet is 0-0 all the
                         * way down, so the order is whatever fell out of the
                         * tiebreaks — insertion order, in practice. That is a
                         * number, not a standing, and "1st in the SEC" in
                         * August is a worse lie than saying nothing. Judged
                         * per conference, because divisions do not all open on
                         * the same weekend.
                         */
                        if ($rows->every(fn (Standing $standing) => $standing->gamesPlayed() === 0)) {
                            return;
                        }

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

    /**
     * Every FBS team for the season, with the marks a picker row renders.
     *
     * The list behind both team pickers — Account's follow search and Home's
     * quick add — so the two cannot drift and only one of them pays for the
     * query. Scoped to FBS and to the season we are IN, because a picker of
     * all 854 teams is not a picker.
     *
     * Plain arrays, not models: this goes through the cache, and an Eloquent
     * model round-trips as `__PHP_Incomplete_Class` and fails on the SECOND
     * request. The logo URLs ride along so a result row can show a mark
     * without hydrating anything.
     *
     * @return list<array{id:int, name:string, logo:?string, logo_dark:?string}>
     */
    public static function fbsTeams(?int $year = null): array
    {
        $year ??= app(CfbCalendar::class)->scoreboardYear();

        // Cache key is versioned: the shape gained logo columns, and a stale
        // entry from before that would render rows with no mark at all.
        return self::$memo["fbs:{$year}"] ??= Cache::remember(
            "picker:teams:v2:{$year}",
            self::CACHE_SECONDS,
            fn () => Team::query()
                ->whereIn('id', TeamSeason::where('season_year', $year)
                    ->where('classification', 'FBS')
                    ->pluck('team_id'))
                ->orderBy('display_name')
                ->get(['id', 'display_name', 'logo', 'logo_dark'])
                ->map(fn (Team $t) => [
                    'id' => $t->id,
                    'name' => $t->display_name,
                    'logo' => $t->logo,
                    'logo_dark' => $t->logo_dark,
                ])
                ->all(),
        );
    }

    /** Reset per-request memoization — tests re-seed between assertions. */
    public static function flush(): void
    {
        self::$memo = [];
    }
}
