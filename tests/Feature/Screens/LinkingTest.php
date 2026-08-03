<?php

use App\Enums\StandingSource;
use App\Models\Athlete;
use App\Models\AthleteTeamSeason;
use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Models\Game;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\Week;
use Livewire\Livewire;

/*
 * Every team, conference and player shown anywhere should be a click target.
 * These assert the shared components are actually wired in, rather than each
 * screen re-implementing a logo and a name as dead text.
 */

beforeEach(function () {
    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
    $this->week = Week::create([
        'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
    ]);

    Conference::factory()->create(['id' => 8, 'name' => 'SEC', 'is_conference' => true]);
    ConferenceSeason::create(['conference_id' => 8, 'season_year' => 2025, 'classification' => 'FBS']);

    $this->georgia = Team::factory()->create([
        'id' => 61, 'slug' => 'georgia-bulldogs', 'display_name' => 'Georgia Bulldogs',
        'logo' => 'https://espn/61.png', 'logo_dark' => 'https://espn/61-dark.png',
    ]);
    $this->alabama = Team::factory()->create([
        'id' => 333, 'slug' => 'alabama-crimson-tide', 'display_name' => 'Alabama Crimson Tide',
    ]);

    foreach ([61, 333] as $id) {
        TeamSeason::create(['team_id' => $id, 'season_year' => 2025, 'conference_id' => 8, 'classification' => 'FBS']);
    }
});

it('links both teams from a game card on the scoreboard', function () {
    Game::factory()->finished(31, 17)->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]);

    Livewire::test('scoreboard')
        ->set('year', 2025)
        ->set('week', 5)
        ->assertSee(route('team', $this->georgia), escape: false)
        ->assertSee(route('team', $this->alabama), escape: false);
});

it('uses the dark logo variant where one exists', function () {
    Game::factory()->finished()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]);

    // Synced all along but never rendered, so light-on-light marks vanished
    // against a dark surface.
    Livewire::test('scoreboard')
        ->set('year', 2025)
        ->set('week', 5)
        ->assertSee('https://espn/61-dark.png', escape: false);
});

it('links teams and the conference from standings', function () {
    Standing::create([
        'season_year' => 2025, 'conference_id' => 8, 'team_id' => 61,
        'source' => StandingSource::Espn, 'conf_wins' => 7, 'conf_losses' => 1,
    ]);

    Livewire::test('standings')
        ->set('year', 2025)
        ->assertSee(route('team', $this->georgia), escape: false)
        // A conference has no page of its own, so its name points at the
        // standings filtered to it.
        ->assertSee('conference=8', escape: false);
});

it('links the team from a player page', function () {
    $athlete = Athlete::create(['id' => 999, 'display_name' => 'Test Player']);
    AthleteTeamSeason::create([
        'athlete_id' => 999, 'team_id' => 61, 'season_year' => 2025,
    ]);

    Livewire::test('player', ['athlete' => $athlete])
        ->assertSee(route('team', $this->georgia), escape: false);
});

it('renders TBD rather than a dead link when a team is missing', function () {
    // FCS opponents and unfilled bowl slots have no team row.
    Game::factory()->finished()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => null,
    ]);

    Livewire::test('scoreboard')
        ->set('year', 2025)
        ->set('week', 5)
        ->assertOk()
        ->assertSee('TBD');
});

it('defaults the scoreboard to a week that has games', function () {
    // Later, empty weeks exist — landing on one shows "Nothing on the slate"
    // as the first thing a visitor ever sees.
    Week::create([
        'season_id' => $this->season->id, 'number' => 16, 'name' => 'Week 16',
        'start_date' => '2025-12-20', 'end_date' => '2025-12-27',
    ]);

    Game::factory()->finished()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]);

    Livewire::test('scoreboard')
        ->assertSet('week', 5)
        ->assertDontSee('Nothing on the slate');
});

it('gives guests navigation at phone width', function () {
    // The bottom nav was auth-gated while the header links are sm:hidden, so a
    // signed-out visitor on a phone had no navigation at all.
    $this->get(route('scoreboard'))
        ->assertOk()
        ->assertSee(route('standings'), escape: false)
        ->assertSee(route('recruiting'), escape: false);
});
