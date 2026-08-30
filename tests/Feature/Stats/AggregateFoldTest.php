<?php

use App\Models\Athlete;
use App\Models\AthleteSeasonStat;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Services\Stats\AggregateAthleteStats;
use Illuminate\Support\Facades\DB;

/*
 * The fold reads a page of GAMES at a time rather than a page of stat rows.
 * The old shape re-sent the season's entire game-id list with every page and
 * asked MySQL for `order by id limit 2000` over a 305,000-row table, which it
 * served by scanning the primary key and discarding 82% of what it read —
 * both of the app's slowest logged queries. What must not move across that
 * rewrite is the arithmetic: the same athletes, the same totals, the same
 * count returned, whichever page a given game's rows land on.
 *
 * The fixture is deliberately larger than one page in both directions — 60
 * games and 2,520 stat rows — so it stays a multi-page test if the page size
 * is ever retuned in either unit.
 */

const FOLD_GAMES = 60;
const FOLD_ATHLETES = 42;

beforeEach(function () {
    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);

    $this->week = Week::create([
        'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
    ]);

    Team::factory()->create(['id' => 2633, 'slug' => 'tennessee', 'display_name' => 'Tennessee Volunteers']);
    Team::factory()->create(['id' => 2199, 'slug' => 'e-michigan', 'display_name' => 'Eastern Michigan Eagles']);

    $athletes = [];

    for ($a = 0; $a < FOLD_ATHLETES; $a++) {
        $athletes[] = ['id' => 8000 + $a, 'display_name' => 'Fold Runner '.$a];
    }

    Athlete::insert($athletes);

    $rows = [];
    $now = now();

    for ($g = 0; $g < FOLD_GAMES; $g++) {
        // Pinned, and one day apart, so the kickoff order the fold reads is
        // the order this file wrote them in.
        $game = Game::factory()->finished()->create([
            'id' => 450000000 + $g,
            'season_id' => $this->season->id,
            'week_id' => $this->week->id,
            'home_team_id' => 2633,
            'away_team_id' => 2199,
            'kickoff_at' => $this->season->created_at->copy()->setDate(2025, 9, 1)->setTime(19, 0)->addDays($g),
        ]);

        foreach ($athletes as $athlete) {
            $rows[] = [
                'athlete_id' => $athlete['id'],
                'game_id' => $game->id,
                'team_id' => 2633,
                'category' => 'rushing',
                'stats' => json_encode([
                    'rushingAttempts' => '3',
                    'rushingYards' => '12',
                    // The season's longest run sits in the middle of the
                    // season, so a max that only survived within one page
                    // would be visibly wrong.
                    'longRushing' => $g === 30 ? '88' : '9',
                    // Recomputed from the components, never averaged.
                    'yardsPerRushAttempt' => '4.0',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
    }

    DB::table('athlete_game_stats')->insert($rows);
});

it('folds the same totals whichever page a game lands on', function () {
    $written = app(AggregateAthleteStats::class)->handle(2025, Season::REGULAR);

    expect($written)->toBe(FOLD_ATHLETES)
        ->and(AthleteSeasonStat::count())->toBe(FOLD_ATHLETES);

    $row = AthleteSeasonStat::where('athlete_id', 8000)->where('category', 'rushing')->sole();

    expect($row->stats['gamesPlayed'])->toBe(FOLD_GAMES)
        ->and($row->stats['rushingAttempts'])->toEqual(3 * FOLD_GAMES)
        ->and($row->stats['rushingYards'])->toEqual(12 * FOLD_GAMES)
        // Summed across pages, then divided once — not an average of averages.
        ->and($row->stats['yardsPerRushAttempt'])->toEqual(4.0)
        // A max, carried across the page that held it.
        ->and($row->stats['longRushing'])->toEqual(88)
        ->and($row->team_id)->toBe(2633);

    // Every athlete, not just the sampled one.
    $totals = AthleteSeasonStat::pluck('stats')->map(fn (array $s): float => $s['rushingYards']);

    expect($totals->unique()->values()->all())->toBe([(float) (12 * FOLD_GAMES)]);
});

it('never sends the whole season of game ids to read one page of box scores', function () {
    /*
     * This is the regression itself. The count of ids on the wire is the thing
     * that made the statement slow, and it is invisible in the totals — a fold
     * that shipped all 60 ids on every page would pass the test above.
     */
    $reads = [];

    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'from `athlete_game_stats`')) {
            $reads[] = count($query->bindings);
        }
    });

    app(AggregateAthleteStats::class)->handle(2025, Season::REGULAR);

    expect(count($reads))->toBeGreaterThan(1)
        ->and(max($reads))->toBeLessThan(FOLD_GAMES);
});

it('returns zero for a season with no games without reading a box score', function () {
    $reads = 0;

    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'from `athlete_game_stats`')) {
            $reads++;
        }
    });

    expect(app(AggregateAthleteStats::class)->handle(1899, Season::REGULAR))->toBe(0)
        ->and(AthleteSeasonStat::count())->toBe(0)
        ->and($reads)->toBe(0);
});

it('lists a transfer under the team he finished with, not the row the walk reached last', function () {
    /*
     * "Last team wins" was decided by whichever stat row the walk reached
     * last, which is only the same thing as "his last game" if the summary
     * sync happened to store the season in order. It does not — a backfilled
     * game is written whenever `cfb:summaries --missing` reaches it — and one
     * 2024 transfer was being listed under the team he left.
     *
     * So both walk orders are pointed the wrong way here on purpose: the later
     * game carries the LOWER game id, and its stat row was written FIRST and
     * so carries the lower row id. Only a comparison on the kickoff answers
     * 2633.
     */
    Athlete::create(['id' => 9001, 'display_name' => 'Portal Entrant']);

    $later = Game::factory()->finished()->create([
        'id' => 460000001, 'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 2633, 'away_team_id' => 2199, 'kickoff_at' => '2025-11-15 19:00:00',
    ]);
    $earlier = Game::factory()->finished()->create([
        'id' => 460000002, 'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 2633, 'away_team_id' => 2199, 'kickoff_at' => '2025-11-08 19:00:00',
    ]);

    $now = now();

    DB::table('athlete_game_stats')->insert([
        ['athlete_id' => 9001, 'game_id' => $later->id, 'team_id' => 2633, 'category' => 'rushing',
            'stats' => json_encode(['rushingAttempts' => '4', 'rushingYards' => '20']),
            'created_at' => $now, 'updated_at' => $now],
        ['athlete_id' => 9001, 'game_id' => $earlier->id, 'team_id' => 2199, 'category' => 'rushing',
            'stats' => json_encode(['rushingAttempts' => '6', 'rushingYards' => '30']),
            'created_at' => $now, 'updated_at' => $now],
    ]);

    app(AggregateAthleteStats::class)->handle(2025, Season::REGULAR);

    $row = AthleteSeasonStat::where('athlete_id', 9001)->where('category', 'rushing')->sole();

    // Both games still count toward the totals; only the badge moves.
    expect($row->team_id)->toBe(2633)
        ->and($row->stats['gamesPlayed'])->toBe(2)
        ->and($row->stats['rushingYards'])->toEqual(50);
});
