<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Team recruiting classes, ranked.
 *
 * Derived, and it has to be: ESPN publishes a per-prospect grade but its
 * `recruiting/{year}/rankings` resource is an empty shell — `{id, name: "ESPN
 * Class Rankings"}` with no entries and every sub-path 404ing.
 *
 * One place rather than two, because the League screen and a team page both
 * show a class rank and two implementations would eventually disagree about
 * what a team's rank is.
 */
class RecruitingClasses
{
    /** How many signees a class is judged on — see the ordering note below. */
    private const COUNTED = 20;

    private const TTL = 900;

    /**
     * Every team with a commitment in the class, best first.
     *
     * Ranked on the TOP TWENTY signees, which is what a recruiting service
     * does, and neither naive alternative survives contact with the data:
     *
     *   average alone put a school with ONE 77-grade signee third in the
     *   country, above Georgia's forty;
     *   total alone put West Virginia's 71 signees (61.1 average) above LSU's
     *   class containing the nation's #1 prospect.
     *
     * Twenty is the size of a real class. The long tail — ESPN lists 40-70
     * "commitments" per school, most of them walk-ons — should not move a
     * ranking.
     *
     * Returns plain ARRAYS. Caching Eloquent models or stdClass brings them
     * back as `__PHP_Incomplete_Class` on the SECOND request, which is how this
     * one gets missed: the first call populates the cache and looks fine.
     *
     * @return list<array{team:array<string,mixed>, signees:int, average:float|null, best:int|null, points:float}>
     */
    public static function forClass(int $class): array
    {
        return Cache::remember("recruiting:classes:{$class}", self::TTL, fn () => DB::query()
            ->fromSub(
                DB::table('recruits')
                    ->where('recruiting_class', $class)
                    ->whereNotNull('committed_team_id')
                    ->selectRaw('committed_team_id, grade, national_rank,
                        row_number() over (partition by committed_team_id order by grade desc) as best_n'),
                'r'
            )
            ->join('teams as t', 't.id', '=', 'r.committed_team_id')
            ->groupBy('t.id', 't.slug', 't.location', 't.display_name', 't.short_display_name', 't.abbreviation', 't.logo', 't.logo_dark')
            ->selectRaw('t.id, t.slug, t.location, t.display_name, t.short_display_name, t.abbreviation, t.logo, t.logo_dark,
                count(*) as signees,
                round(avg(r.grade), 1) as average,
                min(r.national_rank) as best,
                sum(case when r.best_n <= '.self::COUNTED.' then r.grade else 0 end) as points')
            ->orderByDesc('points')
            ->orderByDesc('average')
            ->get()
            // Team columns kept apart from the aggregates so a row can be
            // hydrated into a Team without `signees`/`average` reaching it —
            // Team::make() rejects those as unfillable.
            ->map(fn ($row) => [
                'team' => [
                    'id' => $row->id, 'slug' => $row->slug, 'location' => $row->location,
                    'display_name' => $row->display_name, 'short_display_name' => $row->short_display_name,
                    'abbreviation' => $row->abbreviation, 'logo' => $row->logo, 'logo_dark' => $row->logo_dark,
                ],
                'signees' => (int) $row->signees,
                'average' => $row->average,
                'best' => $row->best,
                'points' => (float) $row->points,
            ])
            ->all());
    }

    /**
     * One team's line, with its national rank in the class.
     *
     * Read from the SAME ranked list the League screen renders, so the two can
     * never report a different rank for the same team.
     *
     * @return array{rank:int, signees:int, average:float|null, best:int|null}|null
     */
    public static function forTeam(int $teamId, int $class): ?array
    {
        foreach (self::forClass($class) as $index => $row) {
            if ($row['team']['id'] === $teamId) {
                return [
                    'rank' => $index + 1,
                    'signees' => $row['signees'],
                    'average' => $row['average'],
                    'best' => $row['best'],
                ];
            }
        }

        return null;
    }
}
