<?php

namespace App\Support;

use App\Models\Conference;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\TeamSeason;
use App\Services\CfbCalendar;
use Illuminate\Support\Facades\Cache;

/**
 * The "who am I looking at" filter — Scores, Stats, Players, Recruiting,
 * Standings and Teams all speak it, through x-scope-filter.
 *
 * Replaces the plain conference dropdown those screens each had. Two things
 * make it more than a rename:
 *
 *   - **Top 25 is the default.** Opening on 800 teams' worth of games is not a
 *     useful first screen; opening on the ranked ones is what a person came for.
 *   - **FBS sorts second**, ahead of the individual conferences, because "all
 *     the teams that matter" is a more common ask than any one league.
 *
 * Conference labels come from `short_name` — ACC, Big Ten, CUSA, MAC. NOT from
 * `abbreviation`, which despite the name holds an ESPN URL slug: `acc`,
 * `big10`, `usa`, `midam`, `belt`. Rendering those would put lowercase slugs
 * across four screens.
 */
class Scope
{
    public const TOP_25 = 'top25';

    public const FBS = 'fbs';

    public const FCS = 'fcs';

    public const SESSION_PREFIX = 'scope.selected';

    private const CACHE_TTL = 3600;

    /**
     * Keep the user's last scope selection for the rest of the session — one
     * memory PER AREA, not one for the app.
     *
     * Scores and League ask the same question in different jobs: on the
     * scoreboard the scope picks whose games are worth watching this Saturday;
     * in League it picks whose season you are reading. Those answers are
     * routinely different — SEC on Scores while League sits on All FBS — and a
     * single memory made every visit to one silently retune the other. Within
     * an area it IS one question: narrowing Standings to the SEC and finding
     * Stats already there is the whole point.
     *
     * The bucket comes from the ROUTE through {@see areaOf()}, so the grouping
     * is Navigation's rather than a second copy of it that drifts.
     *
     * Stored raw. Validity is a property of the READING screen (Stats has no
     * Top 25, Scores lists no FCS conferences), so each screen vets the value
     * against its own menu through remembered() rather than the writer
     * guessing at every reader's vocabulary.
     */
    public static function remember(string $scope, string $route): void
    {
        session()->put(self::sessionKey($route), $scope);
    }

    /**
     * Which memory a screen reads and writes: its AREA's, from Navigation.
     *
     * Asked of Navigation rather than restated here, so moving a screen
     * between areas moves its memory with it. A route no area claims gets a
     * bucket of its own — never a shared one, because guessing an area is how
     * two unrelated screens start overwriting each other's filter.
     *
     * Navigation's own `routes` lists are the map, NOT `currentArea()`: this
     * runs inside a Livewire update, where the request is `/livewire/update`
     * and `routeIs('scoreboard')` is false — the area would resolve to null on
     * every write and every screen would share one nameless bucket.
     */
    public static function areaOf(string $route): string
    {
        foreach (Navigation::areas() as $area) {
            if (in_array($route, $area['routes'], true)) {
                return $area['key'];
            }
        }

        return $route;
    }

    /** The session key backing one area's memory. */
    public static function sessionKey(string $route): string
    {
        return self::SESSION_PREFIX.'.'.self::areaOf($route);
    }

    /**
     * The remembered scope, provided THIS screen's menu can speak it.
     *
     * The flags mirror options(): a remembered value the caller's menu does
     * not list — Top 25 on a leaderboard, an FCS conference on Scores — is
     * treated as nothing rather than adopted, because filter-menu renders an
     * unlisted selection as its first option's label, and a control reading
     * "Top 25" over one conference's games is worse than forgetting the pick.
     * A disabled option (Top 25 before the preseason poll) is refused for the
     * same reason.
     *
     * Null means "nothing usable was remembered" — callers fall back to their
     * own default, never this method. The session entry itself is left alone:
     * one screen declining a value is not the user un-choosing it.
     *
     * `$route` names the screen asking, which is what selects its AREA's
     * memory — Scores never reads League's pick, or the other way about.
     */
    public static function remembered(string $route, int $year, bool $includeFcs = false, bool $top25 = true): ?string
    {
        $scope = session()->get(self::sessionKey($route));

        if (! is_string($scope) || $scope === '') {
            return null;
        }

        $option = collect(self::options($year, $includeFcs, $top25))->firstWhere('value', $scope);

        return ($option !== null && ! $option['disabled']) ? $scope : null;
    }

    /**
     * Options for a season, in presentation order.
     *
     * @return list<array{value:string, label:string}>
     */
    public static function options(int $year, bool $includeFcs = false, bool $top25 = true): array
    {
        /*
         * hasRankings is resolved OUTSIDE the cache and folded into the
         * KEY — the Remember::filled class of guard: when the preseason
         * poll lands mid-TTL, the flag change is a new key, so Top 25
         * cannot stay greyed out for up to an hour after it became real.
         */
        $hasRankings = $top25 && self::hasRankings($year);

        return Cache::remember(
            "scope:options:{$year}:".($includeFcs ? 'all' : 'fbs').':'.($top25 ? 't' : 'f').':'.($hasRankings ? 'r' : 'nr'),
            self::CACHE_TTL,
            function () use ($year, $includeFcs, $top25, $hasRankings) {
                /*
                 * Top 25 is a filter on TEAMS, so it is meaningful on a
                 * scoreboard — "show me the games that matter" — and meaningless
                 * on a statistical leaderboard, where it would silently mean
                 * "the leading rusher among 25 teams" and read as if it were
                 * the national leader.
                 */
                $options = [];

                if ($top25) {
                    /*
                     * Offered but DISABLED when the season has no poll yet,
                     * which is the normal state all summer — the preseason AP
                     * poll does not land until August. Showing it as selectable
                     * and quietly resolving it to FBS is worse than greying it
                     * out: the filter would read "Top 25" while displaying all
                     * 138 teams.
                     */
                    $options[] = [
                        'value' => self::TOP_25,
                        'label' => 'Top 25',
                        'disabled' => ! $hasRankings,
                    ];
                }

                // "All FBS", not "FBS": beside a list of conferences the bare
                // acronym reads as one more league rather than as the whole
                // division, and the option means "everyone in it".
                $options[] = ['value' => self::FBS, 'label' => 'All FBS'];

                if ($includeFcs) {
                    $options[] = ['value' => self::FCS, 'label' => 'All FCS'];
                }

                /*
                 * With FCS in play the conference list doubles (11 FBS + 14
                 * FCS in 2025), so each entry carries its division as `group`
                 * and the menu renders the two under headings. Without FCS
                 * the group stays null and no heading is drawn.
                 */
                foreach (self::conferences($year) as $conference) {
                    $options[] = [
                        'value' => (string) $conference['id'],
                        'label' => $conference['label'],
                        'group' => $includeFcs ? 'FBS' : null,
                    ];
                }

                if ($includeFcs) {
                    foreach (self::conferences($year, 'FCS') as $conference) {
                        $options[] = [
                            'value' => (string) $conference['id'],
                            'label' => $conference['label'],
                            'group' => 'FCS',
                        ];
                    }
                }

                // One shape for every option, so a caller never has to guess
                // whether a key is there.
                return array_map(fn (array $o) => $o + ['disabled' => false, 'group' => null], $options);
            }
        );
    }

    /**
     * Conferences that had teams in a season, for one classification.
     *
     * Read through team_seasons because membership is season-scoped — the whole
     * reason that table exists.
     *
     * @return list<array{id:int, label:string}>
     */
    public static function conferences(int $year, string $classification = 'FBS'): array
    {
        return Conference::query()
            ->whereIn('id', TeamSeason::where('season_year', $year)
                ->where('classification', $classification)
                ->whereNotNull('conference_id')
                ->distinct()
                ->pluck('conference_id'))
            ->where('is_conference', true)
            ->orderBy('name')
            ->get(['id', 'name', 'short_name'])
            ->map(fn (Conference $c) => [
                'id' => $c->id,
                'label' => $c->short_name ?: $c->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Does this season have a poll to rank against yet?
     */
    public static function hasRankings(int $year): bool
    {
        return self::rankedTeamIds($year) !== [];
    }

    /**
     * The scope a screen should open on.
     *
     * Top 25 where a poll exists, FBS otherwise — so the scoreboard never
     * starts on a filter that cannot mean anything.
     */
    public static function defaultFor(int $year): string
    {
        return self::hasRankings($year) ? self::TOP_25 : self::FBS;
    }

    /**
     * The team ids a scope resolves to, or null for "no team restriction".
     *
     * Null is meaningful and is not the same as an empty array: null means do
     * not filter, an empty array means filter to nothing. Conflating them is
     * how a scope with no members would show every game instead of none.
     *
     * @return list<int>|null
     */
    public static function teamIds(string $scope, int $year): ?array
    {
        if ($scope === self::TOP_25) {
            $ranked = self::rankedTeamIds($year);

            /*
             * Still falls through to FBS rather than filtering everything out,
             * as a backstop for a URL carrying `scope=top25` into a season with
             * no poll. The UI disables the option so this should not normally
             * be reachable — an empty Top 25 showing "Nothing on the slate" as
             * a visitor's first screen would be the worse failure.
             */
            return $ranked === [] ? self::teamIds(self::FBS, $year) : $ranked;
        }

        // Cached like rankedTeamIds: membership moves weekly at most, and
        // these lists back every scoped screen's WHERE IN.
        if ($scope === self::FBS || $scope === self::FCS) {
            return Cache::remember("scope:teams:{$scope}:{$year}", self::CACHE_TTL, fn () => TeamSeason::where('season_year', $year)
                ->where('classification', strtoupper($scope))
                ->pluck('team_id')
                ->all());
        }

        if (ctype_digit($scope)) {
            return Cache::remember("scope:teams:conf-{$scope}:{$year}", self::CACHE_TTL, fn () => TeamSeason::where('season_year', $year)
                ->where('conference_id', (int) $scope)
                ->pluck('team_id')
                ->all());
        }

        return null;
    }

    /**
     * Teams in the most recent poll of a season.
     *
     * Uses whichever poll the calendar considers current — CFP once it exists,
     * AP until then — so "Top 25" means the same thing here as it does on the
     * rankings screen rather than quietly being a different list.
     *
     * @return list<int>
     */
    public static function rankedTeamIds(int $year): array
    {
        // The POLL rides the key: when the calendar flips AP -> CFP in
        // November, the new poll is a new key and the old list ages out
        // unread — instead of Top 25 quietly meaning last week's AP for a
        // TTL after the switch.
        $poll = app(CfbCalendar::class)->defaultPoll($year)->value;

        return Cache::remember("scope:top25:{$year}:{$poll}", self::CACHE_TTL, function () use ($year, $poll) {
            $seasonIds = Season::where('year', $year)->pluck('id');

            if ($seasonIds->isEmpty()) {
                return [];
            }

            $latestWeek = Ranking::whereIn('season_id', $seasonIds)
                ->where('poll', $poll)
                ->max('week_id');

            if ($latestWeek === null) {
                return [];
            }

            return Ranking::where('week_id', $latestWeek)
                ->where('poll', $poll)
                ->where('rank', '<=', 25)
                ->orderBy('rank')
                ->pluck('team_id')
                ->all();
        });
    }

    /**
     * Human label for a stored scope value.
     */
    public static function label(string $scope, int $year): string
    {
        foreach (self::options($year, includeFcs: true, top25: true) as $option) {
            if ($option['value'] === $scope) {
                return $option['label'];
            }
        }

        return 'Top 25';
    }
}
