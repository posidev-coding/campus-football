<?php

use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\Game;
use App\Models\GameScoringPlay;
use App\Models\GameSummary;
use App\Models\GameTeamStat;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\Week;
use App\Services\Espn\Sync\SyncGameSummary;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
    $this->week = Week::create([
        'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
    ]);

    $this->georgia = Team::factory()->create(['id' => 61, 'display_name' => 'Georgia Bulldogs', 'abbreviation' => 'UGA']);
    $this->alabama = Team::factory()->create(['id' => 333, 'display_name' => 'Alabama Crimson Tide', 'abbreviation' => 'ALA']);

    // Without these the FBS scope resolves to an empty team list and correctly
    // filters every game out — membership is season-scoped, so it has to exist.
    foreach ([61, 333] as $teamId) {
        TeamSeason::create([
            'team_id' => $teamId,
            'season_year' => 2025,
            'classification' => 'FBS',
        ]);
    }

    $this->game = Game::factory()->finished(31, 17)->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]);

    GameSummary::create([
        'game_id' => $this->game->id,
        'is_final' => true,
        'synced_at' => now(),
        'attendance' => 92746,
    ]);
});

it('renders a completed game for guests', function () {
    $this->get(route('game', $this->game))->assertOk();
});

it('costs no ESPN request for a final game', function () {
    // A final game's summary can never change, so it is fetched once ever and
    // every later visit is a pure database read. This is what makes an archive
    // of 5,000 game pages free to browse.
    Http::fake();

    Livewire::test('game', ['game' => $this->game])->assertOk();

    Http::assertNothingSent();
});

it('renders the team box score in ESPN order, not MySQL JSON order', function () {
    // MySQL does not preserve JSON object key order, so ordering lives in a
    // JSON *array* alongside the map. Storing them scrambled here proves the
    // display order comes from display_stats and not from the map.
    GameTeamStat::create([
        'game_id' => $this->game->id,
        'team_id' => 61,
        'stats' => ['totalYards' => '461', 'firstDowns' => '21'],
        'display_stats' => [
            ['name' => 'firstDowns', 'label' => '1st Downs'],
            ['name' => 'totalYards', 'label' => 'Total Yards'],
        ],
    ]);

    $html = Livewire::test('game', ['game' => $this->game])->set('tab', 'box')->html();

    expect(strpos($html, '1st Downs'))->toBeLessThan(strpos($html, 'Total Yards'));
});

it('renders player lines keyed by name rather than array position', function () {
    $athlete = Athlete::create(['id' => 4690158, 'display_name' => 'Noah Kim', 'slug' => 'noah-kim']);

    AthleteGameStat::create([
        'athlete_id' => $athlete->id,
        'game_id' => $this->game->id,
        'team_id' => 61,
        'category' => 'passing',
        // Deliberately out of display order — v3 indexed stats[0]/stats[1] and
        // broke whenever ESPN reordered.
        'stats' => ['passingYards' => '330', 'completions/passingAttempts' => '25/42'],
        'display_stats' => [
            ['name' => 'completions/passingAttempts', 'label' => 'C/ATT'],
            ['name' => 'passingYards', 'label' => 'YDS'],
        ],
    ]);

    Livewire::test('game', ['game' => $this->game])
        ->set('tab', 'box')
        ->assertSee('Noah Kim')
        ->assertSee('25/42')
        ->assertSee('330');
});

it('orders scoring plays by sequence, not by clock', function () {
    // A football clock counts DOWN, so ascending clock within a quarter
    // reverses it. These two are stored with the later play having the LARGER
    // clock value to make that failure mode visible.
    GameScoringPlay::create([
        'game_id' => $this->game->id, 'team_id' => 61, 'sequence' => 1,
        'period' => 1, 'clock' => '2:11', 'abbreviation' => 'TD',
        'text' => 'First score of the game', 'home_score' => 7, 'away_score' => 0,
    ]);

    GameScoringPlay::create([
        'game_id' => $this->game->id, 'team_id' => 333, 'sequence' => 2,
        'period' => 2, 'clock' => '14:55', 'abbreviation' => 'TD',
        'text' => 'Second score of the game', 'home_score' => 7, 'away_score' => 7,
    ]);

    $html = Livewire::test('game', ['game' => $this->game])->set('tab', 'scoring')->html();

    expect(strpos($html, 'First score'))->toBeLessThan(strpos($html, 'Second score'));
});

it('shows attendance from the summary', function () {
    Livewire::test('game', ['game' => $this->game])->assertSee('92,746');
});

it('tells the user an upcoming game has no box score yet', function () {
    $upcoming = Game::factory()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
        'completed' => false, 'status' => 'pre',
    ]);

    Livewire::test('game', ['game' => $upcoming])
        ->assertOk()
        ->assertSee('Not played yet');
});

it('throttles a live game to one ESPN request per minute across all viewers', function () {
    // The invariant that keeps this screen cheap. Ten people opening the same
    // live game must produce ONE upstream request, not ten.
    Cache::clear();
    Http::fake(['*' => Http::response(['boxscore' => ['teams' => [], 'players' => []]])]);

    $live = Game::factory()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
        'completed' => false, 'status' => 'in',
    ]);

    $sync = app(SyncGameSummary::class);

    $fetched = 0;

    for ($viewer = 0; $viewer < 10; $viewer++) {
        $fetched += $sync->refresh($live->fresh()) ? 1 : 0;
    }

    expect($fetched)->toBe(1);
});

it('links a game card to its game page', function () {
    Livewire::test('scoreboard')
        ->set('scope', 'fbs')
        ->set('week', $this->week->id)
        ->assertSee(route('game', $this->game), escape: false);
});
