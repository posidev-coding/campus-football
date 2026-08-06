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

    private const CACHE_TTL = 3600;

    /**
     * Options for a season, in presentation order.
     *
     * @return list<array{value:string, label:string}>
     */
    public static function options(int $year, bool $includeFcs = false, bool $top25 = true): array
    {
        return Cache::remember(
            "scope:options:{$year}:".($includeFcs ? 'all' : 'fbs').':'.($top25 ? 't' : 'f'),
            self::CACHE_TTL,
            function () use ($year, $includeFcs, $top25) {
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
                        'disabled' => ! self::hasRankings($year),
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

        if ($scope === self::FBS || $scope === self::FCS) {
            return TeamSeason::where('season_year', $year)
                ->where('classification', strtoupper($scope))
                ->pluck('team_id')
                ->all();
        }

        if (ctype_digit($scope)) {
            return TeamSeason::where('season_year', $year)
                ->where('conference_id', (int) $scope)
                ->pluck('team_id')
                ->all();
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
        return Cache::remember("scope:top25:{$year}", self::CACHE_TTL, function () use ($year) {
            $calendar = app(CfbCalendar::class);
            $poll = $calendar->defaultPoll($year)->value;

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
