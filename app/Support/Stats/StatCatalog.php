<?php

namespace App\Support\Stats;

/**
 * What a stat is, how it combines across games, and where it belongs.
 *
 * Everything here is derived from the box score keys ESPN actually publishes,
 * read off our own stored `display_stats` rather than assumed.
 *
 * The grouping follows ESPN's own stats page — Offense / Defense / Special
 * Teams — because that is how people look for this, not alphabetically by
 * category name.
 */
class StatCatalog
{
    public const OFFENSE = 'offense';

    public const DEFENSE = 'defense';

    public const SPECIAL = 'special';

    /**
     * Stats that take the MAXIMUM across games, not the sum.
     *
     * A season's longest run is the longest single run, not the total of every
     * game's longest. Summing these is the kind of error that produces a
     * plausible-looking number nobody checks.
     */
    public const MAX_STATS = [
        'longRushing', 'longReception', 'longPunt',
        'longKickReturn', 'longPuntReturn', 'longFieldGoalMade',
    ];

    /**
     * Rate stats, recomputed from their components rather than averaged.
     *
     * Averaging per-game averages is wrong whenever the denominators differ —
     * a 1-carry game weighs the same as a 30-carry one. Each entry is
     * [numerator, denominator, decimals].
     */
    public const RATE_STATS = [
        'yardsPerPassAttempt' => ['passingYards', 'passingAttempts', 1],
        'yardsPerRushAttempt' => ['rushingYards', 'rushingAttempts', 1],
        'yardsPerReception' => ['receivingYards', 'receptions', 1],
        'yardsPerKickReturn' => ['kickReturnYards', 'kickReturns', 1],
        'yardsPerPuntReturn' => ['puntReturnYards', 'puntReturns', 1],
        'grossAvgPuntYards' => ['puntYards', 'punts', 1],
        'fieldGoalPct' => ['fieldGoalsMade', 'fieldGoalAttempts', 1],
        'completionPct' => ['completions', 'passingAttempts', 1],
    ];

    /**
     * Compound keys ESPN ships as "25/42", split into real components so they
     * can be summed and so rate stats have denominators to work with.
     */
    public const COMPOUND_STATS = [
        'completions/passingAttempts' => ['completions', 'passingAttempts'],
        'fieldGoalsMade/fieldGoalAttempts' => ['fieldGoalsMade', 'fieldGoalAttempts'],
        'extraPointsMade/extraPointAttempts' => ['extraPointsMade', 'extraPointAttempts'],
    ];

    /**
     * Stats that cannot be derived from box scores at all.
     *
     * adjQBR is a proprietary ESPN model, not an arithmetic combination of the
     * columns beside it — there is no honest way to roll it up from per-game
     * values, so it is omitted rather than approximated.
     */
    public const UNDERIVABLE = ['adjQBR', 'QBRating'];

    /**
     * The leaderboards, by side.
     *
     * `category` is the box-score category the stat lives in, and it MATTERS
     * beyond lookup: `interceptions` exists in both `passing` (thrown — a bad
     * outcome) and `interceptions` (caught — a good one). Same key, opposite
     * meaning. Keying a leaderboard on the stat name alone would silently rank
     * quarterbacks by how often they were picked off and call them leaders.
     *
     * @return array<string, list<array{
     *     group:string, category:string, stat:string, label:string, decimals:int, min?:array{0:string,1:int}
     * }>>
     */
    public static function leaderboards(): array
    {
        return [
            self::OFFENSE => [
                ['group' => 'Passing', 'category' => 'passing', 'stat' => 'passingYards', 'label' => 'Passing Yards', 'decimals' => 0],
                ['group' => 'Passing', 'category' => 'passing', 'stat' => 'passingTouchdowns', 'label' => 'Passing TDs', 'decimals' => 0],
                ['group' => 'Passing', 'category' => 'passing', 'stat' => 'completions', 'label' => 'Completions', 'decimals' => 0],
                // Rate stats need a floor or a 1-for-1 passer tops the board.
                ['group' => 'Passing', 'category' => 'passing', 'stat' => 'yardsPerPassAttempt', 'label' => 'Yards / Attempt', 'decimals' => 1, 'min' => ['passingAttempts', 150]],

                ['group' => 'Rushing', 'category' => 'rushing', 'stat' => 'rushingYards', 'label' => 'Rushing Yards', 'decimals' => 0],
                ['group' => 'Rushing', 'category' => 'rushing', 'stat' => 'rushingTouchdowns', 'label' => 'Rushing TDs', 'decimals' => 0],
                ['group' => 'Rushing', 'category' => 'rushing', 'stat' => 'yardsPerRushAttempt', 'label' => 'Yards / Carry', 'decimals' => 1, 'min' => ['rushingAttempts', 100]],

                ['group' => 'Receiving', 'category' => 'receiving', 'stat' => 'receivingYards', 'label' => 'Receiving Yards', 'decimals' => 0],
                ['group' => 'Receiving', 'category' => 'receiving', 'stat' => 'receptions', 'label' => 'Receptions', 'decimals' => 0],
                ['group' => 'Receiving', 'category' => 'receiving', 'stat' => 'receivingTouchdowns', 'label' => 'Receiving TDs', 'decimals' => 0],
            ],

            self::DEFENSE => [
                ['group' => 'Tackles', 'category' => 'defensive', 'stat' => 'totalTackles', 'label' => 'Total Tackles', 'decimals' => 0],
                ['group' => 'Tackles', 'category' => 'defensive', 'stat' => 'soloTackles', 'label' => 'Solo Tackles', 'decimals' => 0],
                ['group' => 'Tackles', 'category' => 'defensive', 'stat' => 'tacklesForLoss', 'label' => 'Tackles For Loss', 'decimals' => 1],

                ['group' => 'Sacks', 'category' => 'defensive', 'stat' => 'sacks', 'label' => 'Sacks', 'decimals' => 1],
                ['group' => 'Sacks', 'category' => 'defensive', 'stat' => 'hurries', 'label' => 'QB Hurries', 'decimals' => 0],
                ['group' => 'Sacks', 'category' => 'defensive', 'stat' => 'passesDefended', 'label' => 'Passes Defended', 'decimals' => 0],

                // The CAUGHT interceptions, from their own category.
                ['group' => 'Interceptions', 'category' => 'interceptions', 'stat' => 'interceptions', 'label' => 'Interceptions', 'decimals' => 0],
                ['group' => 'Interceptions', 'category' => 'interceptions', 'stat' => 'interceptionYards', 'label' => 'Interception Yards', 'decimals' => 0],
                ['group' => 'Interceptions', 'category' => 'interceptions', 'stat' => 'interceptionTouchdowns', 'label' => 'Pick Sixes', 'decimals' => 0],
            ],

            self::SPECIAL => [
                ['group' => 'Kicking', 'category' => 'kicking', 'stat' => 'fieldGoalsMade', 'label' => 'Field Goals Made', 'decimals' => 0],
                ['group' => 'Kicking', 'category' => 'kicking', 'stat' => 'totalKickingPoints', 'label' => 'Kicking Points', 'decimals' => 0],
                ['group' => 'Kicking', 'category' => 'kicking', 'stat' => 'fieldGoalPct', 'label' => 'Field Goal %', 'decimals' => 1, 'min' => ['fieldGoalAttempts', 15]],

                ['group' => 'Punting', 'category' => 'punting', 'stat' => 'grossAvgPuntYards', 'label' => 'Punting Average', 'decimals' => 1, 'min' => ['punts', 30]],
                ['group' => 'Punting', 'category' => 'punting', 'stat' => 'puntsInside20', 'label' => 'Punts Inside 20', 'decimals' => 0],

                ['group' => 'Returning', 'category' => 'kickReturns', 'stat' => 'kickReturnYards', 'label' => 'Kick Return Yards', 'decimals' => 0],
                ['group' => 'Returning', 'category' => 'puntReturns', 'stat' => 'puntReturnYards', 'label' => 'Punt Return Yards', 'decimals' => 0],
            ],
        ];
    }

    /**
     * Team stat groups, mirroring ESPN's own page.
     *
     * `team_season_stats` categories map onto sides: offense is what a team
     * did, defense is what it allowed. ESPN files both under one team, so the
     * split is ours to make.
     *
     * @return array<string, list<array{group:string, category:string, stat:string, label:string, lowerIsBetter?:bool}>>
     */
    public static function teamLeaderboards(): array
    {
        return [
            self::OFFENSE => [
                ['group' => 'Total Offense', 'category' => 'passing', 'stat' => 'totalYards', 'label' => 'Total Yards'],
                ['group' => 'Total Offense', 'category' => 'passing', 'stat' => 'yardsPerGame', 'label' => 'Yards / Game'],
                ['group' => 'Scoring', 'category' => 'scoring', 'stat' => 'totalPoints', 'label' => 'Points'],
                ['group' => 'Scoring', 'category' => 'scoring', 'stat' => 'totalPointsPerGame', 'label' => 'Points / Game'],
                ['group' => 'Passing', 'category' => 'passing', 'stat' => 'netPassingYards', 'label' => 'Passing Yards'],
                ['group' => 'Passing', 'category' => 'passing', 'stat' => 'passingTouchdowns', 'label' => 'Passing TDs'],
                ['group' => 'Rushing', 'category' => 'rushing', 'stat' => 'rushingYards', 'label' => 'Rushing Yards'],
                ['group' => 'Rushing', 'category' => 'rushing', 'stat' => 'rushingTouchdowns', 'label' => 'Rushing TDs'],
            ],

            self::DEFENSE => [
                ['group' => 'Tackles', 'category' => 'defensive', 'stat' => 'totalTackles', 'label' => 'Total Tackles'],
                ['group' => 'Tackles', 'category' => 'defensive', 'stat' => 'tacklesForLoss', 'label' => 'Tackles For Loss'],
                ['group' => 'Pressure', 'category' => 'defensive', 'stat' => 'sacks', 'label' => 'Sacks'],
                ['group' => 'Pressure', 'category' => 'defensive', 'stat' => 'passesDefended', 'label' => 'Passes Defended'],
                ['group' => 'Takeaways', 'category' => 'defensiveInterceptions', 'stat' => 'interceptions', 'label' => 'Interceptions'],
                ['group' => 'Takeaways', 'category' => 'defensiveInterceptions', 'stat' => 'interceptionYards', 'label' => 'Interception Yards'],
            ],

            self::SPECIAL => [
                ['group' => 'Kicking', 'category' => 'kicking', 'stat' => 'fieldGoalsMade', 'label' => 'Field Goals Made'],
                ['group' => 'Kicking', 'category' => 'kicking', 'stat' => 'totalKickingPoints', 'label' => 'Kicking Points'],
                ['group' => 'Punting', 'category' => 'punting', 'stat' => 'grossAvgPuntYards', 'label' => 'Punting Average'],
                ['group' => 'Returning', 'category' => 'returning', 'stat' => 'kickReturnYards', 'label' => 'Kick Return Yards'],
                ['group' => 'Returning', 'category' => 'returning', 'stat' => 'puntReturnYards', 'label' => 'Punt Return Yards'],
            ],
        ];
    }

    /**
     * Every stat the answer layer may resolve, keyed `category.stat`.
     *
     * THE SAME ENTRIES THE LEADERBOARDS RENDER, and that is the point rather
     * than a shortcut: an answer a reader cannot then go and verify on a screen
     * is an answer they have to take on faith. Adding a leaderboard makes its
     * stat askable in the same commit, with the label and the decimals it will
     * be printed with already decided.
     *
     * The key is the PAIR, never the stat alone. `interceptions` lives in both
     * `passing` (thrown — a bad outcome) and `interceptions` (caught — a good
     * one): same word, opposite meaning, and a vocabulary keyed on the word
     * would answer "how many interceptions did he have" with whichever row the
     * database happened to return first.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function answerable(bool $team = false): array
    {
        $boards = $team ? self::teamLeaderboards() : self::leaderboards();

        $pairs = [];

        foreach ($boards as $side => $entries) {
            foreach ($entries as $entry) {
                $pairs[$entry['category'].'.'.$entry['stat']] = [...$entry, 'side' => $side];
            }
        }

        return $pairs;
    }

    /**
     * The same vocabulary as a block of prompt text.
     *
     * Generated rather than written out, so the list a model is given cannot
     * drift from the list the resolver will accept — the two would disagree
     * silently, and the symptom would be a model confidently naming a stat that
     * is then declined as unanswerable.
     */
    public static function vocabulary(bool $team = false): string
    {
        $lines = [];

        foreach (self::answerable($team) as $key => $entry) {
            $lines[] = '  '.$key.' — '.$entry['label'];
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, string>
     */
    public static function sideLabels(): array
    {
        return [
            self::OFFENSE => 'Offense',
            self::DEFENSE => 'Defense',
            self::SPECIAL => 'Special Teams',
        ];
    }

    /**
     * The groups within a side, in presentation order.
     *
     * @return list<string>
     */
    public static function groups(string $side, bool $team = false): array
    {
        $boards = $team ? self::teamLeaderboards() : self::leaderboards();

        return collect($boards[$side] ?? [])->pluck('group')->unique()->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function boardsFor(string $side, string $group, bool $team = false): array
    {
        $boards = $team ? self::teamLeaderboards() : self::leaderboards();

        return collect($boards[$side] ?? [])->where('group', $group)->values()->all();
    }
}
