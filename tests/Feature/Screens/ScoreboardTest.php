<?php

use App\Models\Conference;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\Week;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
    $this->week = Week::create([
        'season_id' => $this->season->id,
        'number' => 5,
        'name' => 'Week 5',
        'start_date' => '2025-09-23',
        'end_date' => '2025-09-29',
    ]);

    $conference = Conference::factory()->create(['id' => 8, 'name' => 'SEC']);
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
        ->set('year', 2025)
        ->set('week', 5)
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

    Livewire::test('scoreboard')->set('year', 2025)->set('week', 5)->assertOk();

    Http::assertNothingSent();
});

it('filters games to a conference using season-scoped membership', function () {
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
        ->set('year', 2025)
        ->set('week', 5)
        ->set('conference', 8)
        ->assertSee('Georgia Bulldogs')
        ->assertDontSee('Some Independent');
});

it('only polls while a game is actually in progress', function () {
    Game::factory()->finished()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]);

    Livewire::test('scoreboard')->set('year', 2025)->set('week', 5)
        ->assertDontSee('wire:poll', escape: false);

    Game::factory()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
        'status' => 'in',
        'completed' => false,
    ]);

    Livewire::test('scoreboard')->set('year', 2025)->set('week', 5)
        ->assertSee('wire:poll', escape: false);
});

it('shows an empty state rather than erroring when a week has no games', function () {
    Livewire::test('scoreboard')
        ->set('year', 2025)
        ->set('week', 5)
        ->assertOk()
        ->assertSee('Nothing on the slate');
});
