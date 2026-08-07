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
