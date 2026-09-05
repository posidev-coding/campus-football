<?php

use App\Models\Game;
use App\Models\Season;

/*
 * Guarantees about the factories themselves, because a factory that derives
 * one column from another in `definition()` keeps the value an override was
 * about to replace — and the disagreement it leaves behind is silent. This
 * suite has paid for that twice: SeasonFactory's dates survived a pinned
 * `year` and moved every default season in the app, and GameFactory's
 * `kickoff_day` survived a pinned `kickoff_at`.
 */

it('derives a game\'s stored day from the kickoff the caller pinned', function () {
    $game = Game::factory()->create([
        'season_id' => Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR])->id,
        'home_team_id' => null, 'away_team_id' => null,
        'kickoff_at' => '2025-09-06 19:30:00',
    ]);

    expect($game->kickoff_day)->toBe('Sat');
});

it('reads that day in the app\'s timezone, not UTC', function () {
    // 00:30 UTC on Sunday is Saturday night in ET, and `Game::slateEligible()`
    // filters on this column — so a UTC read would drop a whole evening of
    // real Saturday games out of a contest slate.
    $game = Game::factory()->create([
        'season_id' => Season::factory()->create(['year' => 2026, 'type' => Season::REGULAR])->id,
        'home_team_id' => null, 'away_team_id' => null,
        'kickoff_at' => '2026-09-06 00:30:00',
    ]);

    expect($game->kickoff_day)->toBe('Sat')
        ->and($game->kickoff_at->format('D'))->toBe('Sun');
});

it('leaves a day the caller pinned deliberately alone', function () {
    $game = Game::factory()->create([
        'season_id' => Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR])->id,
        'home_team_id' => null, 'away_team_id' => null,
        'kickoff_at' => '2025-09-06 19:30:00',
        'kickoff_day' => 'Wed',
    ]);

    expect($game->kickoff_day)->toBe('Wed');
});

it('keeps a season\'s dates in the year the caller pinned', function () {
    // The other half of the same rule, already fixed — asserted here so the
    // two factories cannot drift apart on it.
    $season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);

    expect($season->start_date->year)->toBe(2025)
        ->and($season->end_date->year)->toBe(2025);
});

/*
 * ...and guarantees about the CLOCK those fixtures are read against, because
 * pinning a fixture to an absolute instant only pins it while the wall clock
 * is behind that instant. On 2026-09-05 the clock passed the shared pick'em
 * kickoff of 19:30 and nineteen tests that had never travelled began reading
 * their upcoming game as kicked — in isolation as well as in the full suite,
 * and with no day on which it would have recovered by itself.
 */

it('pins the suite\'s now, so a test that never travels does not read the real clock', function () {
    expect(now()->toDateTimeString())->toBe(SUITE_NOW);
});

it('keeps the shared fixture\'s kickoff in the FUTURE, which is the thing that broke', function () {
    /*
     * The assertion the nineteen were all making implicitly. It has to hold
     * whatever the real date is — that is the whole difference between a
     * defused bomb and a reset one.
     */
    [$season, $week] = pickemSeasonWeek();

    expect(pickemGame($season, $week)->kickoff_at->isFuture())->toBeTrue();
});

it('lets an explicit travelTo win over the pinned default', function () {
    // 215 calls across the suite depend on this; a beforeEach that could not
    // be overridden would silently retune every one of them.
    $this->travelTo('2026-09-06 16:01:00');

    expect(now()->toDateTimeString())->toBe('2026-09-06 16:01:00');
});
