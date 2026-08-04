<?php

use App\Models\Conference;
use App\Models\Game;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\Week;
use App\Support\Scope;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->season = Season::factory()->create([
        'year' => 2025, 'type' => Season::REGULAR,
        'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
    ]);

    $this->week = Week::create([
        'season_id' => $this->season->id,
        'number' => 5,
        'name' => 'Week 5',
        'start_date' => '2025-09-23',
        'end_date' => '2025-09-29',
    ]);

    $conference = Conference::factory()->create(['id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC']);
    $this->georgia = Team::factory()->create(['id' => 61, 'display_name' => 'Georgia Bulldogs']);
    $this->alabama = Team::factory()->create(['id' => 333, 'display_name' => 'Alabama Crimson Tide']);

    foreach ([61, 333] as $teamId) {
        TeamSeason::create([
            'team_id' => $teamId,
            'season_year' => 2025,
            'conference_id' => $conference->id,
            'classification' => 'FBS',
        ]);
    }
});

it('renders the scoreboard for guests', function () {
    $this->get(route('scoreboard'))->assertOk();
});

it('shows games for the selected week', function () {
    Game::factory()->finished(31, 17)->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]);

    Livewire::test('scoreboard')
        ->set('scope', Scope::FBS)
        ->set('week', $this->week->id)
        ->assertSee('Georgia Bulldogs')
        ->assertSee('Alabama Crimson Tide');
});

it('never calls ESPN while rendering', function () {
    // The single most important assertion on this screen. v3 called ESPN inside
    // render(), so a live game cost one upstream request per viewer per poll.
    Http::fake();

    Game::factory()->finished()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]);

    Livewire::test('scoreboard')->set('scope', Scope::FBS)->set('week', $this->week->id)->assertOk();

    Http::assertNothingSent();
});

it('scopes games through season-scoped conference membership', function () {
    $outsider = Team::factory()->create(['display_name' => 'Some Independent']);

    Game::factory()->finished()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]);

    Game::factory()->finished()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => $outsider->id,
        'away_team_id' => Team::factory()->create()->id,
    ]);

    Livewire::test('scoreboard')
        ->set('week', $this->week->id)
        ->set('scope', '8')
        ->assertSee('Georgia Bulldogs')
        ->assertDontSee('Some Independent');
});

it('defaults to Top 25 and lists FBS second', function () {
    // Opening on every game in the country is not a useful first screen.
    expect(Livewire::test('scoreboard')->get('scope'))->toBe(Scope::TOP_25);

    $options = Scope::options(2025);

    expect($options[0]['value'])->toBe(Scope::TOP_25)
        ->and($options[1]['value'])->toBe(Scope::FBS);
});

it('labels conferences with short_name, never the slug abbreviation', function () {
    // `conferences.abbreviation` holds an ESPN URL slug — `sec`, `big10`,
    // `midam` — so rendering it would put lowercase slugs across four screens.
    $labels = collect(Scope::options(2025))->pluck('label');

    expect($labels)->toContain('SEC')
        ->and($labels)->not->toContain('sec');
});

it('restricts Top 25 to ranked teams', function () {
    Ranking::create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'poll' => 'ap', 'team_id' => 61, 'rank' => 1, 'record' => '5-0',
    ]);

    Game::factory()->finished()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
    ]);

    $unranked = Team::factory()->create(['display_name' => 'Unranked State']);

    Game::factory()->finished()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => $unranked->id, 'away_team_id' => Team::factory()->create()->id,
    ]);

    Livewire::test('scoreboard')
        ->set('week', $this->week->id)
        ->set('scope', Scope::TOP_25)
        ->assertSee('Georgia Bulldogs')
        ->assertDontSee('Unranked State');
});

it('has no season selector', function () {
    // Scores is a "what is on now" screen. Comparing years belongs on
    // Standings, Rankings, Stats and Leaders, where it is the point.
    expect(Livewire::test('scoreboard')->instance())
        ->not->toHaveProperty('year');
});

it('only polls while a game is actually in progress', function () {
    Game::factory()->finished()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]);

    Livewire::test('scoreboard')->set('scope', Scope::FBS)->set('week', $this->week->id)
        ->assertDontSee('wire:poll', escape: false);

    Game::factory()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
        'status' => 'in',
        'completed' => false,
    ]);

    Livewire::test('scoreboard')->set('scope', Scope::FBS)->set('week', $this->week->id)
        ->assertSee('wire:poll', escape: false);
});

it('shows an empty state rather than erroring when a week has no games', function () {
    Livewire::test('scoreboard')
        ->set('scope', Scope::FBS)
        ->set('week', $this->week->id)
        ->assertOk()
        ->assertSee('Nothing on the slate');
});
