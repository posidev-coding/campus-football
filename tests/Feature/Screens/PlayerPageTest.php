<?php

use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\AthleteTeamSeason;
use App\Models\Game;
use App\Models\Position;
use App\Models\Season;
use App\Models\Team;
use App\Services\Espn\Sync\SyncAthleteStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    config()->set('espn.http.rate_limit', 0);

    $this->team = Team::factory()->create(['id' => 61, 'display_name' => 'Georgia Bulldogs']);
    Position::create(['id' => 8, 'name' => 'Quarterback', 'abbreviation' => 'QB']);

    $this->athlete = Athlete::create([
        'id' => 4685578,
        'display_name' => 'Gunner Stockton',
        'display_height' => "6' 1\"",
        'display_weight' => '215 lbs',
        'birth_city' => 'Tiger',
        'birth_state' => 'GA',
        'headshot_url' => 'https://a.espncdn.com/i/headshots/college-football/players/full/4685578.png',
    ]);

    AthleteTeamSeason::create([
        'athlete_id' => $this->athlete->id,
        'team_id' => 61,
        'season_year' => 2025,
        'jersey' => '14',
        'position_id' => 8,
        'experience_class' => 'Junior',
    ]);
});

it('renders a player page for guests', function () {
    $this->get(route('player', $this->athlete))->assertOk();
});

it('shows measurables and hometown, since ESPN publishes no bio prose', function () {
    Livewire::test('player', ['athlete' => $this->athlete])
        ->assertSee('Gunner Stockton')
        ->assertSee("6' 1")
        ->assertSee('215 lbs')
        ->assertSee('Tiger, GA')
        ->assertSee('Junior');
});

it('does not fetch the game log on first render', function () {
    // The log is the one per-athlete feed. Fetching it eagerly would put an
    // upstream round-trip on the critical path of every player page view.
    Http::fake();

    Livewire::test('player', ['athlete' => $this->athlete])->assertOk();

    Http::assertNothingSent();
});

it('fetches and renders the game log on demand', function () {
    $season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
    Game::factory()->finished()->create([
        'id' => 401769073,
        'season_id' => $season->id,
        'short_name' => 'OLE @ UGA',
    ]);

    Http::fake(['*gamelog*' => Http::response([
        'names' => ['completions', 'passingAttempts', 'passingYards', 'passingTouchdowns'],
        'labels' => ['CMP', 'ATT', 'YDS', 'TD'],
        'seasonTypes' => [[
            'displayName' => '2025 Regular Season',
            'categories' => [[
                'type' => 'passing',
                'events' => [[
                    'eventId' => '401769073',
                    'stats' => ['18', '31', '203', '1'],
                ]],
            ]],
        ]],
    ])]);

    Livewire::test('player', ['athlete' => $this->athlete])
        ->call('loadGameLog')
        ->assertSee('OLE @ UGA')
        ->assertSee('203');

    $row = AthleteGameStat::where('athlete_id', $this->athlete->id)->sole();

    // Addressable by name, not by array position.
    expect($row->stats)->toEqualCanonicalizing([
        'completions' => '18',
        'passingAttempts' => '31',
        'passingYards' => '203',
        'passingTouchdowns' => '1',
    ]);

    // Order is asserted separately, because MySQL's JSON type reorders object
    // keys on write — so ordering has to live in a JSON array, not the map.
    expect($row->display_stats)
        ->toBe(['completions', 'passingAttempts', 'passingYards', 'passingTouchdowns']);
});

it('collapses concurrent viewers into a single upstream fetch', function () {
    Http::fake(['*gamelog*' => Http::response(['names' => [], 'seasonTypes' => []])]);

    $stats = app(SyncAthleteStats::class);

    $stats->refreshGameLog($this->athlete->id);
    $stats->refreshGameLog($this->athlete->id);
    $stats->refreshGameLog($this->athlete->id);

    // Three viewers, one request — the lock is what makes an on-demand feed
    // safe to expose on a public page.
    Http::assertSentCount(1);
});

it('skips log entries for games we have not ingested', function () {
    Http::fake(['*gamelog*' => Http::response([
        'names' => ['passingYards'],
        'seasonTypes' => [[
            'categories' => [[
                'type' => 'passing',
                'events' => [['eventId' => '999999999', 'stats' => ['203']]],
            ]],
        ]],
    ])]);

    app(SyncAthleteStats::class)->refreshGameLog($this->athlete->id);

    // A foreign key would otherwise abort the whole log.
    expect(AthleteGameStat::count())->toBe(0);
});

it('shows an empty state when a player has no recorded stats', function () {
    Http::fake(['*gamelog*' => Http::response('', 404)]);

    Livewire::test('player', ['athlete' => $this->athlete])
        ->call('loadGameLog')
        ->assertSee('No game log');
});
