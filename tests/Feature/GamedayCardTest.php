<?php

use App\Enums\GamedayStatus;
use App\Models\Game;
use App\Models\GamedayWeek;
use App\Models\Season;
use App\Models\Team;
use App\Models\User;
use App\Models\Venue;

/*
 * One fixed slot in every state, so nothing below the card reflows between a
 * week that has been announced and one that has not — and no card at all out
 * of season, where a permanent "not on the air" is clutter rather than news.
 */

beforeEach(function () {
    $this->season = Season::factory()->create([
        'year' => 2026,
        'type' => Season::REGULAR,
        'start_date' => '2026-08-24',
        'end_date' => '2026-12-14',
    ]);

    $this->lsu = Team::factory()->create(['display_name' => 'LSU Tigers', 'location' => 'LSU']);
    $venue = Venue::create(['id' => 99001, 'name' => 'Tiger Stadium', 'city' => 'Baton Rouge', 'state' => 'LA']);

    $this->game = Game::factory()->create([
        'season_id' => $this->season->id,
        'venue_id' => $venue->id,
        'home_team_id' => $this->lsu->id,
        'kickoff_at' => '2026-09-05 19:30:00',
        'name' => 'Clemson Tigers at LSU Tigers',
    ]);

    $this->travelTo('2026-09-02 09:00');
});

function announceGameday(array $overrides = []): GamedayWeek
{
    return GamedayWeek::factory()->create([
        'season_year' => 2026,
        'saturday' => '2026-09-05',
        'status' => GamedayStatus::Proposed,
        'site' => 'Tiger Stadium',
        'city' => 'Baton Rouge',
        'state' => 'LA',
        ...$overrides,
    ]);
}

it('says so plainly when ESPN has not announced a site', function () {
    // The empty state is a real answer. GameDay's next stop usually lands
    // Sunday or Monday, so an empty Monday is the system working.
    $this->get('/')
        ->assertOk()
        ->assertSee('College GameDay')
        ->assertSee('TBA');
});

it('shows the place, the game and the kickoff once it resolves', function () {
    announceGameday(['game_id' => $this->game->id, 'team_id' => $this->lsu->id]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Baton Rouge, LA')
        ->assertSee('Clemson Tigers at LSU Tigers')
        // The whole card is the door to the game screen.
        ->assertSee(route('game', $this->game), escape: false);
});

it('does not render at all out of season', function () {
    /*
     * A dead card for seven months is clutter, not presence — and the slot
     * has to disappear entirely rather than render empty, or the home page
     * carries a permanent hole from January to August.
     */
    $this->travelTo('2026-06-15 09:00');

    $this->get('/')->assertOk()->assertDontSee('College GameDay');
});

it('gets louder and takes the colors when the bus is on your campus', function () {
    announceGameday(['game_id' => $this->game->id, 'team_id' => $this->lsu->id]);

    $user = User::factory()->create();
    $user->followedTeams()->attach($this->lsu->id, ['position' => 1]);

    $mine = $this->actingAs($user)->get('/');
    $mine->assertOk()->assertSee('--team-accent', escape: false);

    // Somebody who does not follow LSU gets the league's headline, in the
    // league's neutral treatment.
    $stranger = $this->actingAs(User::factory()->create())->get('/');
    $stranger->assertOk()->assertSee('Baton Rouge, LA')->assertDontSee('--team-accent', escape: false);
});

it('offers no link on a week with nowhere to go', function () {
    // A card that looks tappable and answers with nothing is a broken promise
    // the moment somebody taps it.
    $this->get('/')->assertOk()->assertDontSee('/games/', escape: false);
});
