<?php

use App\Enums\GamedayStatus;
use App\Models\GamedayWeek;

/*
 * The storage guarantees, before anything writes to it.
 *
 * `cfb:gameday` runs every morning Sunday through Thursday until the week
 * resolves, so "one row per Saturday" is not a tidiness preference — it is
 * the difference between a card with one answer and a card with five.
 */

it('keeps one row per Saturday however many times the week is checked', function () {
    foreach (range(1, 5) as $morning) {
        GamedayWeek::record(2026, '2026-09-05', ['site' => "run {$morning}"]);
    }

    expect(GamedayWeek::count())->toBe(1)
        ->and(GamedayWeek::first()->site)->toBe('run 5');
});

it('never overwrites a site a human confirmed', function () {
    /*
     * The commissioner owns the line and a human owns this: an automated
     * suggestion, a person with the final word. A later run that disagrees
     * with a confirmed row is the run that is wrong.
     */
    GamedayWeek::factory()->confirmed()->create([
        'season_year' => 2026,
        'saturday' => '2026-09-05',
        'site' => 'LSU',
    ]);

    GamedayWeek::record(2026, '2026-09-05', [
        'site' => 'Oklahoma',
        'city' => 'Norman',
        'status' => GamedayStatus::Proposed,
    ]);

    $row = GamedayWeek::first();

    expect($row->site)->toBe('LSU')
        ->and($row->city)->toBe('Baton Rouge')
        ->and($row->status)->toBe(GamedayStatus::Confirmed);
});

it('still records that a confirmed week was checked again', function () {
    // "We looked and left it alone" is worth being able to see — otherwise a
    // confirmed row is indistinguishable from a command that stopped running.
    $week = GamedayWeek::factory()->confirmed()->create([
        'season_year' => 2026,
        'saturday' => '2026-09-05',
        'checked_at' => now()->subWeek(),
    ]);

    GamedayWeek::record(2026, '2026-09-05', ['site' => 'Oklahoma']);

    expect($week->fresh()->checked_at->isToday())->toBeTrue();
});

it('defaults to unknown rather than to a site nobody announced', function () {
    // The whole feature produces a location, which is exactly why the
    // no-defaults rule has to be written down here.
    $week = new GamedayWeek;

    expect($week->status)->toBe(GamedayStatus::Unknown)
        ->and($week->site)->toBeNull()
        ->and($week->status->isKnown())->toBeFalse();
});
