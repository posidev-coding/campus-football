<?php

use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\AthleteTeamSeason;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Services\Espn\Sync\SyncGameSummary;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * The player box score, written as a batch.
 *
 * storePlayerStats() used to call updateOrCreate() once per athlete-category
 * line, which made FetchGameSummary the slowest job in the window. These tests
 * pin what it stores — the same rows, the same decoded casts, the same skips —
 * and that it now reaches athlete_game_stats in one read and one write per
 * chunk instead of two round trips per line.
 */

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);

    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
    $week = Week::create([
        'season_id' => $this->season->id, 'number' => 7, 'name' => 'Week 7',
        'start_date' => '2025-10-14', 'end_date' => '2025-10-20',
    ]);

    Team::factory()->create(['id' => 2633, 'display_name' => 'Tennessee']);
    Team::factory()->create(['id' => 333, 'display_name' => 'Alabama']);

    $this->game = Game::factory()->create([
        'season_id' => $this->season->id, 'week_id' => $week->id,
        'home_team_id' => 2633, 'away_team_id' => 333,
        'completed' => true, 'status' => 'post',
        'kickoff_at' => '2025-10-18 23:30:00',
    ]);

    // ONE fake that serves whatever the test last handed it: sequential
    // Http::fake calls stack, and the first '*' registered keeps winning.
    $this->body = [];
    Http::fake(fn () => Http::response($this->body));

    /** Fetch $body through the real sync, past the client's response cache. */
    $this->sync = function (array $body): void {
        Cache::flush();
        $this->body = $body;

        expect(app(SyncGameSummary::class)->handle($this->game))->toBeTrue();
    };

    /** Every statement that touched athlete_game_stats while $run ran. */
    $this->statStatements = function (callable $run): array {
        $statements = [];

        DB::listen(function ($query) use (&$statements) {
            if (str_contains($query->sql, 'athlete_game_stats')) {
                $statements[] = strtolower(strtok(ltrim($query->sql), ' '));
            }
        });

        $run();

        return $statements;
    };
});

/**
 * A box score shaped like ESPN's: positional `stats` beside a parallel `keys`,
 * plus every line the sync must refuse.
 */
function playerBoxScore(array $overrides = []): array
{
    $qb = ['id' => '4430001', 'firstName' => 'Nico', 'lastName' => 'Arm', 'displayName' => 'Nico Arm', 'jersey' => '12'];
    $back = ['id' => '4430002', 'displayName' => 'Dylan Legs', 'jersey' => '6'];
    $receiver = ['id' => '4430003', 'displayName' => 'Ryan Hands', 'jersey' => '1'];

    return array_replace_recursive([
        'boxscore' => [
            'teams' => [],
            'players' => [
                [
                    'team' => ['id' => '2633'],
                    'statistics' => [
                        [
                            'name' => 'passing',
                            'keys' => ['completions/passingAttempts', 'passingYards', 'passingTouchdowns', 'interceptions'],
                            'labels' => ['C/ATT', 'YDS', 'TD'],
                            'athletes' => [
                                // Fewer values than keys: zipped against the leading keys.
                                ['athlete' => $qb, 'stats' => ['21/30', '287', '3']],
                            ],
                        ],
                        [
                            'name' => 'rushing',
                            'keys' => ['rushingAttempts', 'rushingYards'],
                            'labels' => ['CAR', 'YDS'],
                            'athletes' => [
                                ['athlete' => $qb, 'stats' => ['6', '41']],
                                ['athlete' => $back, 'stats' => ['19', '124']],
                                // ESPN's team-level pseudo-athlete.
                                ['athlete' => ['id' => '-1', 'displayName' => 'Team'], 'stats' => ['2', '-4']],
                                // A player listed with no line.
                                ['athlete' => ['id' => '4430008', 'displayName' => 'Bench Guy'], 'stats' => []],
                                // A line with nobody attached.
                                ['stats' => ['1', '3']],
                            ],
                        ],
                        // No name, and no keys: neither can be stored by name.
                        ['keys' => ['fumbles'], 'labels' => ['FUM'], 'athletes' => [['athlete' => $back, 'stats' => ['1']]]],
                        ['name' => 'kicking', 'keys' => [], 'labels' => [], 'athletes' => [['athlete' => $back, 'stats' => ['1']]]],
                    ],
                ],
                [
                    'team' => ['id' => '333'],
                    'statistics' => [[
                        'name' => 'receiving',
                        'keys' => ['receptions', 'receivingYards'],
                        'labels' => ['REC', 'YDS'],
                        'athletes' => [['athlete' => $receiver, 'stats' => ['7', '96']]],
                    ]],
                ],
                // A team we do not know, and a side with no team at all.
                ['team' => ['id' => '9999'], 'statistics' => [[
                    'name' => 'passing', 'keys' => ['passingYards'], 'labels' => ['YDS'],
                    'athletes' => [['athlete' => ['id' => '4430009', 'displayName' => 'Stranger'], 'stats' => ['10']]],
                ]]],
                ['statistics' => [[
                    'name' => 'passing', 'keys' => ['passingYards'], 'labels' => ['YDS'],
                    'athletes' => [['athlete' => ['id' => '4430010', 'displayName' => 'Nobody'], 'stats' => ['10']]],
                ]]],
            ],
        ],
    ], $overrides);
}

/**
 * The game's lines as the app reads them: through the model and its casts.
 *
 * `stats` is key-sorted because MySQL JSON does not keep object key order —
 * which is why `display_stats` exists, and why its order is asserted as-is.
 */
function storedLines(Game $game): array
{
    return AthleteGameStat::where('game_id', $game->id)
        ->orderBy('athlete_id')->orderBy('category')
        ->get()
        ->map(fn (AthleteGameStat $row) => [
            'athlete_id' => $row->athlete_id,
            'team_id' => $row->team_id,
            'category' => $row->category,
            'stats' => collect($row->stats)->sortKeys()->all(),
            'display_stats' => $row->display_stats,
        ])
        ->all();
}

it('stores every line zipped by name, with the casts intact', function () {
    ($this->sync)(playerBoxScore());

    $expected = [
        [
            'athlete_id' => 4430001, 'team_id' => 2633, 'category' => 'passing',
            'stats' => ['completions/passingAttempts' => '21/30', 'passingTouchdowns' => '3', 'passingYards' => '287'],
            'display_stats' => [
                ['name' => 'completions/passingAttempts', 'label' => 'C/ATT'],
                ['name' => 'passingYards', 'label' => 'YDS'],
                ['name' => 'passingTouchdowns', 'label' => 'TD'],
            ],
        ],
        [
            'athlete_id' => 4430001, 'team_id' => 2633, 'category' => 'rushing',
            'stats' => ['rushingAttempts' => '6', 'rushingYards' => '41'],
            'display_stats' => [['name' => 'rushingAttempts', 'label' => 'CAR'], ['name' => 'rushingYards', 'label' => 'YDS']],
        ],
        [
            'athlete_id' => 4430002, 'team_id' => 2633, 'category' => 'rushing',
            'stats' => ['rushingAttempts' => '19', 'rushingYards' => '124'],
            'display_stats' => [['name' => 'rushingAttempts', 'label' => 'CAR'], ['name' => 'rushingYards', 'label' => 'YDS']],
        ],
        [
            'athlete_id' => 4430003, 'team_id' => 333, 'category' => 'receiving',
            'stats' => ['receivingYards' => '96', 'receptions' => '7'],
            'display_stats' => [['name' => 'receptions', 'label' => 'REC'], ['name' => 'receivingYards', 'label' => 'YDS']],
        ],
    ];

    // Read back twice: the second read proves the stored JSON is the cast's
    // own shape, not a string the first decode happened to tolerate.
    expect(storedLines($this->game))->toBe($expected)
        ->and(storedLines($this->game))->toBe($expected);

    // Not double-encoded: the column holds a JSON object, not a JSON string.
    $raw = DB::table('athlete_game_stats')->where('athlete_id', 4430002)->value('stats');
    expect(json_decode($raw, true))->toEqual(['rushingAttempts' => '19', 'rushingYards' => '124']);

    $row = AthleteGameStat::where('athlete_id', 4430002)->sole();
    expect($row->created_at)->not->toBeNull()
        ->and($row->updated_at)->not->toBeNull();
});

it('still skips every line it cannot store, rather than writing a row of nulls', function () {
    ($this->sync)(playerBoxScore());

    // Pseudo-athlete, empty line, unknown team, no team, nameless and keyless
    // categories: none of them is a row, and none of them made an athlete.
    expect(AthleteGameStat::where('game_id', $this->game->id)->count())->toBe(4)
        ->and(Athlete::whereIn('id', [4430008, 4430009, 4430010])->exists())->toBeFalse()
        ->and(AthleteGameStat::where('athlete_id', 4430002)->pluck('category')->all())->toBe(['rushing']);
});

it('ensures each athlete once, without clobbering a richer roster row', function () {
    Athlete::create([
        'id' => 4430001, 'first_name' => 'Nico', 'last_name' => 'Arm',
        'display_name' => 'Nico Arm', 'headshot_url' => 'https://a.espncdn.com/roster.png',
    ]);

    ($this->sync)(playerBoxScore());

    expect(Athlete::find(4430001)->headshot_url)->toBe('https://a.espncdn.com/roster.png')
        ->and(Athlete::find(4430002)->display_name)->toBe('Dylan Legs')
        ->and(AthleteTeamSeason::where('season_year', 2025)->orderBy('athlete_id')->pluck('team_id', 'athlete_id')->all())
        ->toBe([4430001 => 2633, 4430002 => 2633, 4430003 => 333]);
});

it('reaches athlete_game_stats in one read and one write, not two per line', function () {
    $statements = ($this->statStatements)(fn () => ($this->sync)(playerBoxScore()));

    expect($statements)->toBe(['select', 'insert']);
});

it('chunks a big box score instead of sending one unbounded statement', function () {
    // 300 athletes in two categories: 600 lines, so two statements of <= 500.
    $athletes = collect(range(1, 300))->map(fn ($n) => [
        'athlete' => ['id' => (string) (5000000 + $n), 'displayName' => "Player {$n}"],
        'stats' => [(string) $n, (string) ($n * 2)],
    ])->all();

    $body = ['boxscore' => ['teams' => [], 'players' => [[
        'team' => ['id' => '2633'],
        'statistics' => [
            ['name' => 'rushing', 'keys' => ['rushingAttempts', 'rushingYards'], 'labels' => ['CAR', 'YDS'], 'athletes' => $athletes],
            ['name' => 'receiving', 'keys' => ['receptions', 'receivingYards'], 'labels' => ['REC', 'YDS'], 'athletes' => $athletes],
        ],
    ]]]];

    $statements = ($this->statStatements)(fn () => ($this->sync)($body));

    expect($statements)->toBe(['select', 'insert', 'insert'])
        ->and(AthleteGameStat::where('game_id', $this->game->id)->count())->toBe(600)
        ->and(AthleteGameStat::where(['athlete_id' => 5000300, 'category' => 'receiving'])->sole()->stats)
        ->toEqual(['receptions' => '300', 'receivingYards' => '600']);
});

it('re-running an unchanged summary writes nothing and keeps the timestamps', function () {
    $this->travelTo('2025-10-19 03:00:00');
    ($this->sync)(playerBoxScore());
    $before = storedLines($this->game);

    $this->travelTo('2025-10-19 03:05:00');
    $statements = ($this->statStatements)(fn () => ($this->sync)(playerBoxScore()));

    expect($statements)->toBe(['select'])
        ->and(storedLines($this->game))->toBe($before)
        ->and(AthleteGameStat::where('game_id', $this->game->id)->pluck('updated_at')->map->toDateTimeString()->unique()->all())
        ->toBe(['2025-10-19 03:00:00']);
});

it('updates a changed line in place, the way updateOrCreate did', function () {
    $this->travelTo('2025-10-19 03:00:00');
    ($this->sync)(playerBoxScore());

    // A stat correction to one line — the back's yardage.
    $corrected = playerBoxScore();
    $corrected['boxscore']['players'][0]['statistics'][1]['athletes'][1]['stats'] = ['19', '131'];

    $this->travelTo('2025-10-19 03:05:00');
    $statements = ($this->statStatements)(fn () => ($this->sync)($corrected));

    $back = AthleteGameStat::where(['athlete_id' => 4430002, 'category' => 'rushing'])->sole();
    $quarterback = AthleteGameStat::where(['athlete_id' => 4430001, 'category' => 'rushing'])->sole();

    expect($statements)->toBe(['select', 'insert'])
        ->and(AthleteGameStat::where('game_id', $this->game->id)->count())->toBe(4)
        ->and($back->stats)->toEqual(['rushingAttempts' => '19', 'rushingYards' => '131'])
        ->and($back->created_at->toDateTimeString())->toBe('2025-10-19 03:00:00')
        ->and($back->updated_at->toDateTimeString())->toBe('2025-10-19 03:05:00')
        ->and($quarterback->updated_at->toDateTimeString())->toBe('2025-10-19 03:00:00');
});
