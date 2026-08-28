<?php

namespace App\Support;

use App\Models\Athlete;
use App\Models\AthleteSeasonStat;
use App\Models\Team;
use App\Models\User;
use App\Services\CfbCalendar;
use App\Services\Stats\AggregateAthleteStats;
use App\Support\Stats\StatCatalog;

/**
 * Three questions worth tapping, on the screen where nobody knows they can ask.
 *
 * DISCOVERY IS THE WHOLE JOB. A reader who never types a question never learns
 * the answer path exists, and no amount of placeholder text teaches the SHAPE
 * of a question that works. One tap on a real example does, and it does it with
 * their own team's name in it.
 *
 * SO EVERY EXAMPLE MUST RESOLVE. A suggestion the app then declines is worse
 * than no suggestion — it teaches that asking does not work, on the one attempt
 * the reader was ever going to make. Each line below is built from a metric in
 * {@see StatCatalog::answerable()} and a subject we have
 * already looked up, and the player example is dropped rather than offered when
 * the name would not resolve unambiguously.
 *
 * Cached a day as PLAIN STRINGS — never the models they were built from, which
 * come back as `__PHP_Incomplete_Class` on the second request and fail nowhere
 * near here.
 */
class AskExamples
{
    private const TTL = 86400;

    /**
     * The static fallback is Tennessee, and it is the documented one: the
     * pilot audience is Tennessee alumni, and a canned school in example copy
     * is otherwise somebody's rival. Georgia never appears here.
     */
    private const FALLBACK_TEAM = 'Tennessee';

    /**
     * @return list<string>
     */
    public static function for(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $year = app(CfbCalendar::class)->resultsYear();

        return Remember::filled("ask:examples:v1:{$user->getKey()}:{$year}", self::TTL,
            fn (): array => self::build($user, $year));
    }

    /**
     * @return list<string>
     */
    private static function build(User $user, int $year): array
    {
        $team = $user->followedTeams()->first(['teams.id', 'location', 'display_name']);
        $name = $team?->location ?? self::FALLBACK_TEAM;

        $examples = [
            // A team question, in their own team's name. The metric is
            // `scoring.totalPoints`, which every FBS program has.
            "How many points did {$name} score last season?",
        ];

        $passer = $team === null ? null : self::leadingPasser($team, $year);

        if ($passer !== null) {
            // The headline shape: one person, one stat, one season.
            $examples[] = "How many passing yards did {$passer} throw last season?";
        }

        // A leaderboard needs no name to resolve, so it is the one example
        // that cannot fail on a thin database.
        $examples[] = 'Who leads the country in rushing yards?';

        return $examples;
    }

    /**
     * The team's leading passer, but only if the search that will run when the
     * example is tapped finds exactly that person.
     *
     * The answer layer refuses an ambiguous name on purpose, so an example
     * carrying one would be a button that always declines.
     */
    private static function leadingPasser(Team $team, int $year): ?string
    {
        $top = AthleteSeasonStat::query()
            ->where('season_year', $year)
            ->where('season_type', AggregateAthleteStats::FULL_SEASON)
            ->where('category', 'passing')
            ->where('team_id', $team->id)
            ->get(['athlete_id', 'stats'])
            ->sortByDesc(fn (AthleteSeasonStat $row): float => (float) ($row->stats['passingYards'] ?? 0))
            ->first();

        $name = $top === null ? null : Athlete::query()->whereKey($top->athlete_id)->value('display_name');

        if ($name === null) {
            return null;
        }

        return Search::players($name, limit: 2)->count() === 1 ? $name : null;
    }
}
