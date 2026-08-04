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
        ->set('scope', 'fbs')
        ->set('week', $this->week->id)
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
        ->set('scope', 'fbs')
        ->set('week', $this->week->id)
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
        // A conference now has a page of its own. It used to deep-link back
        // into a filtered standings page, which answered one question and left
        // the reader on a screen about something else.
        ->assertSee(route('conference', ['conference' => 8, 'year' => 2025]), escape: false);
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
        ->set('scope', 'fbs')
        ->set('week', $this->week->id)
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

    // `week` holds a week ID, not a number: the postseason's "Bowls" is also
    // week 1, so a number-keyed selector collides the two and makes the bowl
    // slate unreachable.
    Livewire::test('scoreboard')
        ->set('scope', 'fbs')
        ->assertSet('week', $this->week->id)
        ->assertDontSee('Nothing on the slate');
});

it('gives guests navigation at phone width', function () {
    /*
     * The bottom nav was once auth-gated while the header links were
     * sm:hidden, so a signed-out visitor on a phone had no navigation at all.
     *
     * Asserts the AREA tabs, not every section. Sections are now scoped to the
     * current area, so Recruiting is deliberately absent from a Scores page —
     * it is one tab away in League rather than listed on every screen.
     */
    $this->get(route('scoreboard'))
        ->assertOk()
        ->assertSee(route('home'), escape: false)
        ->assertSee(route('standings'), escape: false)
        ->assertSee(route('login'), escape: false);
});

it('keeps the right rail additive rather than load-bearing', function () {
    // The rail is desktop-only decoration. Its markup is hidden below lg, so
    // nothing reachable only from the rail may exist — a phone user must still
    // get to everything.
    $response = $this->get(route('scoreboard'));

    $response->assertOk()
        ->assertSee('lg:flex', escape: false)   // rail is gated on lg
        ->assertSee('sm:hidden', escape: false); // bottom nav retires at sm
});

it('caps content width at a laptop rather than stretching', function () {
    $this->get(route('scoreboard'))
        ->assertOk()
        ->assertSee('max-w-7xl', escape: false);
});
