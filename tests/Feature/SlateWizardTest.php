<?php

use App\Enums\ContestMode;
use App\Models\Slate;
use Livewire\Livewire;

/*
 * The commissioner's wizard: guided steps by mode, WHOLE-POINT steppers
 * that can never leave the half-point grid, and a preview that is the
 * clubhouse's own pick surface rendered read-only.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

it('walks a Triple Option week through five stations', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Tiered);
    pickemDraftSlate($contest);

    Livewire::actingAs($commissioner)->test('slate-builder', ['group' => $group])
        ->assertSee('Step 1 of 5')
        ->assertSee('Games')
        ->call('next')
        ->assertSee('Tiers')
        ->assertSee('T1: 5/5')
        ->call('next')
        ->assertSee('Lines')
        ->call('next')
        ->assertSee('Tiebreaker')
        ->call('next')
        ->assertSee('Publish the slate');
});

it('skips the tiers station for Shotgun, and refuses to be steered into it', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    pickemDraftSlate($contest);

    Livewire::actingAs($commissioner)->test('slate-builder', ['group' => $group])
        ->assertSee('Step 1 of 4')
        // A bookmarked ?step=tiers on a Shotgun group normalizes home
        // rather than rendering a station the mode does not have.
        ->set('step', 'tiers')
        ->assertSet('step', 'games');
});

it('nudges a line by WHOLE points and never off the half-point grid', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftSlate($contest);
    $slateGame = $slate->games()->first();

    $wizard = Livewire::actingAs($commissioner)->test('slate-builder', ['group' => $group]);

    // Book -6.5, seeded -6.5: one tap up is 7.5 — half-point, one whole
    // point moved. (The old ±0.5 steppers produced 7.0, which the action
    // THROWS at — every tap was a 500 in a validation coat.)
    $wizard->call('nudge', $slateGame->id, 1);
    expect((float) $slateGame->fresh()->spread)->toBe(-7.5);

    $wizard->call('nudge', $slateGame->id, -1)->call('nudge', $slateGame->id, -1);
    expect((float) $slateGame->fresh()->spread)->toBe(-5.5);
});

it('stops quietly at the band\'s edges instead of throwing', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftSlate($contest);
    $slateGame = $slate->games()->first();

    $wizard = Livewire::actingAs($commissioner)->test('slate-builder', ['group' => $group]);

    // Book -6.5: the band is [3.5, 9.5]. Walk to the ceiling...
    $wizard->call('nudge', $slateGame->id, 1)
        ->call('nudge', $slateGame->id, 1)
        ->call('nudge', $slateGame->id, 1);
    expect((float) $slateGame->fresh()->spread)->toBe(-9.5);

    // ...and the next tap is a quiet no-op, the disabled button's mirror.
    $wizard->call('nudge', $slateGame->id, 1);
    expect((float) $slateGame->fresh()->spread)->toBe(-9.5);
});

it('holds the law on a WHOLE-NUMBER book: 3.0 seeds 2.5 and tops out at 5.5', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    [$season, $week] = pickemSeasonWeek();
    $slate = Slate::factory()->create(['contest_id' => $contest->id, 'week_id' => $week->id]);

    $game = pickemGame($season, $week);
    pickemOdd($game, ['spread' => -3.0]);

    $wizard = Livewire::actingAs($commissioner)->test('slate-builder', ['group' => $group]);
    $wizard->call('add', $game->id);

    $slateGame = $slate->games()->where('game_id', $game->id)->first();
    expect((float) $slateGame->spread)->toBe(-2.5);

    // Band [0.5, 6.0]; the half-point grid's last legal stop is 5.5.
    $wizard->call('nudge', $slateGame->id, 1)
        ->call('nudge', $slateGame->id, 1)
        ->call('nudge', $slateGame->id, 1);
    expect((float) $slateGame->fresh()->spread)->toBe(-5.5);

    $wizard->call('nudge', $slateGame->id, 1);
    expect((float) $slateGame->fresh()->spread)->toBe(-5.5);

    // And the floor: 2.5 → 1.5 → 0.5, then quiet.
    foreach (range(1, 6) as $i) {
        $wizard->call('nudge', $slateGame->id, -1);
    }
    expect((float) $slateGame->fresh()->spread)->toBe(-0.5);
});

it('walks a moved line back to the book', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftSlate($contest);
    $slateGame = $slate->games()->first();

    Livewire::actingAs($commissioner)->test('slate-builder', ['group' => $group])
        ->call('nudge', $slateGame->id, 1)
        ->call('nudge', $slateGame->id, 1)
        ->call('resetLine', $slateGame->id);

    expect((float) $slateGame->fresh()->spread)->toBe(-6.5);
});

it('previews the slate as a participant would see it, read-only', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Tiered);
    pickemDraftSlate($contest);

    Livewire::actingAs($commissioner)->test('slate-builder', ['group' => $group])
        ->set('step', 'preview')
        // The clubhouse's own surface: tier headings, the tiebreaker
        // question — and not one tappable side.
        ->assertSee('Tier 1')
        ->assertSee('-6.5')
        ->assertDontSee('wire:click="pick(', escape: false);
});
