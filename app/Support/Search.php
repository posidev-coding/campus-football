<?php

namespace App\Support;

use App\Models\Athlete;
use App\Models\Coach;
use App\Models\Conference;
use App\Models\Game;
use App\Models\Recruit;
use App\Models\Team;
use Illuminate\Support\Collection;

/**
 * Global search over teams, players, coaches, conferences and games — shared
 * by the Home panel, the /search page and the ⌘K palette, so none can drift.
 *
 * Scout's database engine, deliberately: everything searchable is already in
 * our own MySQL, so this is one query per group against source tables — no
 * external service, no index to sync, and search stays the fastest interaction
 * in the app. The engine has no relevance scoring, which is fine because
 * relevance here is DOMAIN knowledge, not text statistics: a live game beats a
 * 2021 one and a current starter beats a 2019 transfer no matter what the
 * strings look like. Each group defines its order in its query callback.
 *
 * Ranked teams float by a PHP re-sort of the fetched page rather than SQL:
 * rank lives in TeamGlance's cached map, and every ranked team is FBS, so the
 * FBS-first SQL order has already pulled them into the page being sorted.
 */
class Search
{
    /** Below this a query matches most of the database and is not useful. */
    public const MIN_LENGTH = 2;

    public static function tooShort(string $query): bool
    {
        return mb_strlen(trim($query)) < self::MIN_LENGTH;
    }

    /**
     * `%` and `_` are LIKE wildcards; typed literally they should match
     * literally, not blow the query open.
     *
     * Public because the Players index builds its own LIKE rather than going
     * through Scout — it filters a season's roster, not the athletes table —
     * and two escaping rules for the same input is how one of them goes wrong.
     */
    public static function term(string $query): string
    {
        return addcslashes(trim($query), '%_\\');
    }

    /**
     * FBS first, then ranked, then alphabetical.
     *
     * @return Collection<int, Team>
     */
    public static function teams(string $query, int $limit = 6): Collection
    {
        if (self::tooShort($query)) {
            return collect();
        }

        $year = TeamGlance::year();

        $teams = Team::search(self::term($query))
            ->query(fn ($q) => $q
                ->select(['id', 'slug', 'location', 'display_name', 'short_display_name', 'abbreviation', 'logo', 'logo_dark'])
                // NULLs sort last under DESC, so a team with no season row
                // lands after every classified one.
                ->orderByRaw(
                    "(select ts.classification = 'FBS' from team_seasons ts where ts.team_id = teams.id and ts.season_year = ?) desc",
                    [$year],
                )
                ->orderBy('display_name'))
            ->take($limit)
            ->get();

        $ranks = TeamGlance::ranks();

        return $teams
            ->sortBy(fn (Team $team) => $ranks[$team->id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Active players first, then whoever played most recently — search used to
     * rank players who left years ago above current starters.
     *
     * @return Collection<int, Athlete>
     */
    public static function players(string $query, int $limit = 6): Collection
    {
        if (self::tooShort($query)) {
            return collect();
        }

        return Athlete::search(self::term($query))
            ->query(fn ($q) => $q
                ->select(['id', 'slug', 'display_name', 'headshot_url', 'birth_city', 'birth_state', 'is_active'])
                ->with([
                    'latestSeason.team:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                    'latestSeason.position:id,abbreviation',
                ])
                ->orderByDesc('is_active')
                // The denormalized column the season rows stamp on save —
                // this was a correlated MAX() subquery per matching row,
                // re-derived on every keystroke. NULLs (no season at all)
                // sort last under DESC, the same place MAX() put them.
                ->orderByDesc('latest_season_year')
                ->orderBy('display_name'))
            ->take($limit)
            ->get();
    }

    /**
     * Whoever coaches NOW first — same idea as players.
     *
     * @return Collection<int, Coach>
     */
    public static function coaches(string $query, int $limit = 4): Collection
    {
        if (self::tooShort($query)) {
            return collect();
        }

        return Coach::search(self::term($query))
            ->query(fn ($q) => $q
                ->with('latestSeason.team:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark')
                ->orderByDesc('latest_season_year')
                ->orderBy('display_name'))
            ->take($limit)
            ->get();
    }

    /**
     * Real conferences before ESPN's divisions and groupings — only 79 of 118
     * rows are conferences at all.
     *
     * @return Collection<int, Conference>
     */
    public static function conferences(string $query, int $limit = 4): Collection
    {
        if (self::tooShort($query)) {
            return collect();
        }

        return Conference::search(self::term($query))
            ->query(fn ($q) => $q
                ->orderByDesc('is_conference')
                ->orderBy('name'))
            ->take($limit)
            ->get();
    }

    /**
     * Live first, then upcoming, then finished — and within each, whatever is
     * nearest to now. A search for a team during their game should put that
     * game on top.
     *
     * @return Collection<int, Game>
     */
    public static function games(string $query, int $limit = 5): Collection
    {
        if (self::tooShort($query)) {
            return collect();
        }

        return Game::search(self::term($query))
            ->query(fn ($q) => $q
                ->with([
                    'homeTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                    'awayTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                ])
                ->orderByRaw("case
                    when completed = 0 and status in ('in', 'halftime', 'end-period') then 0
                    when completed = 0 and kickoff_at >= now() then 1
                    else 2
                end")
                ->orderByRaw('abs(timestampdiff(second, now(), kickoff_at))'))
            ->take($limit)
            ->get();
    }

    /**
     * Prospects who have NOT enrolled yet.
     *
     * Scoped to `athlete_id IS NULL`, and that is the load-bearing part. About
     * half of an older class eventually appears on a roster we hold, and those
     * people are already found by players() — surfacing them here too would put
     * one person in a result list twice under two headings, with the two rows
     * pointing at different places. Recruiting covers the ones a player search
     * cannot reach.
     *
     * Newest class first, then national rank: an unranked 2028 sophomore with a
     * matching surname is less interesting than a ranked 2027 signee. Unranked
     * prospects sort last rather than first, which `order by rank` alone would
     * do with nulls.
     *
     * @return Collection<int, Recruit>
     */
    public static function recruits(string $query, int $limit = 4): Collection
    {
        if (self::tooShort($query)) {
            return collect();
        }

        return Recruit::search(self::term($query))
            ->query(fn ($q) => $q
                ->whereNull('athlete_id')
                ->with([
                    'committedTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                    'position:id,abbreviation',
                ])
                ->orderByDesc('recruiting_class')
                ->orderByRaw('national_rank is null, national_rank')
                ->orderBy('display_name'))
            ->take($limit)
            ->get();
    }
}
