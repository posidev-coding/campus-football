<?php

use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\Venue;
use App\Support\GamedayResolver;
use Carbon\CarbonImmutable;

/*
 * The resolver is the guard, so these are the guard's tests.
 *
 * Every case below was validated against the real feed payload captured
 * 2026-08-24 and against our own venues/games data, and the pairs matter as
 * much as the matches: Austin resolves to a DIFFERENT game on the two
 * Saturdays, which is why the date cannot be dropped from the key.
 */

function venue(int $id, string $name, string $city, string $state): Venue
{
    return Venue::create(['id' => $id, 'name' => $name, 'city' => $city, 'state' => $state]);
}

function gameAt(Venue $venue, string $kickoff, Season $season): Game
{
    return Game::factory()->create([
        'season_id' => $season->id,
        'venue_id' => $venue->id,
        'kickoff_at' => $kickoff,
    ]);
}

beforeEach(function () {
    // One shared season: SeasonFactory draws years without replacement from a
    // twelve-year range, and every game here would take another draw.
    $this->season = Season::factory()->create(['year' => 2026]);
    $this->resolver = app(GamedayResolver::class);
});

it('resolves the live feed payload to the right game, both weeks', function () {
    $batonRouge = venue(99001, 'Tiger Stadium', 'Baton Rouge', 'LA');
    $austin = venue(99002, 'DKR-Texas Memorial', 'Austin', 'TX');

    $lsu = gameAt($batonRouge, '2026-09-05 19:30:00', $this->season);
    $texas = gameAt($austin, '2026-09-12 19:30:00', $this->season);

    expect($this->resolver->resolve('Baton Rouge', 'LA', '2026-09-05')?->id)->toBe($lsu->id)
        // The feed shipped this one upper-cased on the same day. Casing is
        // normalized, never trusted.
        ->and($this->resolver->resolve('AUSTIN', 'TX', '2026-09-12')?->id)->toBe($texas->id);
});

it('lands on a different game in the same city one week later', function () {
    /*
     * THE REASON THE DATE IS IN THE KEY. Austin on Sep 5 is not the Austin
     * the feed means on Sep 12. A resolver keyed on city alone puts the wrong
     * opponent on the home page and looks entirely correct doing it.
     */
    $austin = venue(99002, 'DKR-Texas Memorial', 'Austin', 'TX');

    $september5 = gameAt($austin, '2026-09-05 19:00:00', $this->season);
    $september12 = gameAt($austin, '2026-09-12 19:30:00', $this->season);

    expect($this->resolver->resolve('Austin', 'TX', '2026-09-05')?->id)->toBe($september5->id)
        ->and($this->resolver->resolve('Austin', 'TX', '2026-09-12')?->id)->toBe($september12->id);
});

it('refuses to choose when a city hosts two games that day', function () {
    // A campus game beside a neutral-site game is the real shape of this, and
    // a coin flip between them is worse than saying nothing.
    $austin = venue(99002, 'DKR-Texas Memorial', 'Austin', 'TX');
    $other = venue(99003, 'Q2 Stadium', 'Austin', 'TX');

    gameAt($austin, '2026-09-05 15:00:00', $this->season);
    gameAt($other, '2026-09-05 19:00:00', $this->season);

    expect($this->resolver->resolve('Austin', 'TX', '2026-09-05'))->toBeNull();
});

it('returns null when the place contradicts our own data', function () {
    // The feed's `map` block currently reads "Norman Oklahoma" under an LSU
    // matchup. A location that matches no game is rejected, never rendered.
    venue(99001, 'Tiger Stadium', 'Baton Rouge', 'LA');

    expect($this->resolver->resolve('Norman', 'OK', '2026-09-05'))->toBeNull();
});

it('counts a Saturday night kickoff as Saturday, not Sunday', function () {
    /*
     * 20:00 ET on Saturday is 00:00 UTC on Sunday. Matching the UTC date
     * drops precisely the late window a GameDay broadcast leads into, and it
     * would fail only for night games — the ones most likely to be the pick.
     */
    $batonRouge = venue(99001, 'Tiger Stadium', 'Baton Rouge', 'LA');
    $night = gameAt($batonRouge, '2026-09-06 00:15:00', $this->season);

    expect($this->resolver->resolve('Baton Rouge', 'LA', '2026-09-05')?->id)->toBe($night->id);
});

it('reads a host campus off the game, and nobody off a neutral site', function () {
    $venue = venue(99004, 'Mercedes-Benz Stadium', 'Atlanta', 'GA');
    $home = Team::factory()->create();

    $campus = Game::factory()->create([
        'season_id' => $this->season->id,
        'venue_id' => $venue->id,
        'home_team_id' => $home->id,
        'kickoff_at' => '2026-09-05 19:00:00',
        'neutral_site' => false,
    ]);

    $neutral = Game::factory()->create([
        'season_id' => $this->season->id,
        'venue_id' => $venue->id,
        'kickoff_at' => '2026-09-12 19:00:00',
        'neutral_site' => true,
    ]);

    expect($this->resolver->hostTeam($campus)?->id)->toBe($home->id)
        ->and($this->resolver->hostTeam($neutral))->toBeNull();
});

it('normalizes the two location shapes the feed ships on the same day', function () {
    $resolver = app(GamedayResolver::class);

    expect($resolver->parseLocation('Baton Rouge, LA'))->toBe(['city' => 'Baton Rouge', 'state' => 'LA'])
        ->and($resolver->parseLocation('AUSTIN, TX'))->toBe(['city' => 'Austin', 'state' => 'TX'])
        ->and($resolver->parseLocation('  College Station ,  tx '))->toBe(['city' => 'College Station', 'state' => 'TX']);
});

it('returns null for a location it cannot read rather than half of one', function () {
    $resolver = app(GamedayResolver::class);

    expect($resolver->parseLocation('Baton Rouge'))->toBeNull()
        ->and($resolver->parseLocation('Baton Rouge, Louisiana'))->toBeNull()
        ->and($resolver->parseLocation(''))->toBeNull()
        ->and($resolver->parseLocation('Somewhere, TX, USA'))->toBeNull();
});

it('reads a date-cast Saturday as a calendar date, not as midnight UTC', function () {
    /*
     * `gameday_weeks.saturday` has a `date` cast, so it arrives as midnight
     * UTC — and converting that instant into Eastern lands at 20:00 the
     * evening BEFORE, resolving the wrong Saturday. Nothing throws: the admin
     * override just quietly finds no game, and reads as our schedule
     * disagreeing with a city the human can plainly see is right.
     */
    $batonRouge = venue(99001, 'Tiger Stadium', 'Baton Rouge', 'LA');
    $game = gameAt($batonRouge, '2026-09-05 19:30:00', $this->season);

    $asStored = CarbonImmutable::parse('2026-09-05', 'UTC');

    expect($this->resolver->resolve('Baton Rouge', 'LA', $asStored)?->id)->toBe($game->id);
});
