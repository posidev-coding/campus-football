<?php

namespace App\Support;

use App\Models\Conference;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\TeamSeason;
use App\Services\CfbCalendar;
use Illuminate\Support\Facades\Cache;

/**
 * The "who am I looking at" filter shared by Scores, Stats, Leaders and Teams.
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
    public static function options(int $year, bool $includeFcs = false): array
    {
        return Cache::remember(
            "scope:options:{$year}:".($includeFcs ? 'all' : 'fbs'),
            self::CACHE_TTL,
            function () use ($year, $includeFcs) {
                $options = [
                    ['value' => self::TOP_25, 'label' => 'Top 25'],
                    ['value' => self::FBS, 'label' => 'FBS'],
                ];

                if ($includeFcs) {
                    $options[] = ['value' => self::FCS, 'label' => 'FCS'];
                }

                foreach (self::conferences($year) as $conference) {
                    $options[] = [
                        'value' => (string) $conference['id'],
                        'label' => $conference['label'],
                    ];
                }

                return $options;
            }
        );
    }

    /**
     * FBS conferences that had teams in a season.
     *
     * Read through team_seasons because membership is season-scoped — the whole
     * reason that table exists.
     *
     * @return list<array{id:int, label:string}>
     */
    public static function conferences(int $year): array
    {
        return Conference::query()
            ->whereIn('id', TeamSeason::where('season_year', $year)
                ->where('classification', 'FBS')
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
             * No poll for this season yet — which is the normal state of things
             * all summer, since the preseason AP poll does not land until
             * August. Falling through to FBS matters: an empty Top 25 would
             * filter every game out and show "Nothing on the slate" as the
             * first thing a visitor ever sees.
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
        foreach (self::options($year, includeFcs: true) as $option) {
            if ($option['value'] === $scope) {
                return $option['label'];
            }
        }

        return 'Top 25';
    }
}
