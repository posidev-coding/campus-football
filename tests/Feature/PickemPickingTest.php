<?php

use App\Actions\EnterTiebreaker;
use App\Actions\MakePick;
use App\Actions\PublishSlate;
use App\Exceptions\HandleRequired;
use App\Exceptions\NotGroupMember;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\PickLocked;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\SlateEntry;
use App\Models\User;
use App\Models\WalletEntry;
use Livewire\Livewire;

/*
 * Phase 5 slice 5: picking. The lock is temporal and per game, privacy
 * until lock is a query rule, a missed pick stays an absent row, and every
 * gate lives in MakePick — the sheet's disabled buttons are decoration.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

/** A published board plus a verified, handled member ready to pick. */
function pickemLiveBoard(): array
{
    [$commissioner, $group, $contest] = pickemContest();
    $slate = pickemDraftBoard($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    $member = User::factory()->create(['handle' => 'picksix', 'admin' => true]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    return [$member, $group, $slate->fresh()];
}

// ---------------------------------------------------------------- MakePick

it('records a pick, seats the entry, and pays entry XP once per slate', function () {
    [$member, , $slate] = pickemLiveBoard();
    [$first, $second] = $slate->games()->with('game')->orderBy('position')->take(2)->get();

    app(MakePick::class)->handle($member, $first, $first->game->home_team_id);
    app(MakePick::class)->handle($member, $second, $second->game->away_team_id);

    expect(Pick::where('user_id', $member->id)->count())->toBe(2)
        ->and(SlateEntry::where(['slate_id' => $slate->id, 'user_id' => $member->id])->count())->toBe(1)
        // Two picks, ONE keyed entry grant — the wallet index is the cap.
        ->and(WalletEntry::where('user_id', $member->id)->where('reason', 'pickem-entered')->count())->toBe(1);
});

it('lets a mind change until kickoff, in the same row', function () {
    [$member, , $slate] = pickemLiveBoard();
    $slateGame = $slate->games()->with('game')->first();

    app(MakePick::class)->handle($member, $slateGame, $slateGame->game->home_team_id);
    app(MakePick::class)->handle($member, $slateGame, $slateGame->game->away_team_id);

    $picks = Pick::where('user_id', $member->id)->get();
    expect($picks)->toHaveCount(1)
        ->and($picks->first()->picked_team_id)->toBe($slateGame->game->away_team_id);
});

it('locks at kickoff by clock, and early by feed', function () {
    [$member, , $slate] = pickemLiveBoard();
    $slateGame = $slate->games()->with('game')->first();

    // By feed, before the scheduled time: the game kicked early.
    $slateGame->game->update(['status' => 'in']);
    expect(fn () => app(MakePick::class)->handle($member, $slateGame->fresh(), $slateGame->game->home_team_id))
        ->toThrow(PickLocked::class);

    // By clock, at the exact scheduled second.
    $slateGame->game->update(['status' => null]);
    $this->travelTo('2026-09-05 19:30:00');
    expect(fn () => app(MakePick::class)->handle($member->fresh(), $slateGame->fresh(), $slateGame->game->home_team_id))
        ->toThrow(PickLocked::class);
});

it('holds every gate: verification, handle, membership, and the game itself', function () {
    [$member, $group, $slate] = pickemLiveBoard();
    $slateGame = $slate->games()->with('game')->first();
    $home = $slateGame->game->home_team_id;

    $unverified = User::factory()->unverified()->create(['handle' => 'ghost']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $unverified->id]);
    expect(fn () => app(MakePick::class)->handle($unverified, $slateGame, $home))
        ->toThrow(PickemParticipationGated::class);

    $handleless = User::factory()->handleless()->create();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $handleless->id]);
    expect(fn () => app(MakePick::class)->handle($handleless, $slateGame, $home))
        ->toThrow(HandleRequired::class);

    $outsider = User::factory()->create(['handle' => 'lurker']);
    expect(fn () => app(MakePick::class)->handle($outsider, $slateGame, $home))
        ->toThrow(NotGroupMember::class);

    expect(fn () => app(MakePick::class)->handle($member, $slateGame, 999999))
        ->toThrow(InvalidArgumentException::class);

    // Nothing above left a row behind — a refused pick is no pick.
    expect(Pick::count())->toBe(0);
});

// ------------------------------------------------------------- tiebreaker

it('takes a tiebreaker call until that game kicks off', function () {
    [$member, , $slate] = pickemLiveBoard();

    app(EnterTiebreaker::class)->handle($member, $slate, 52);
    app(EnterTiebreaker::class)->handle($member, $slate, 55);
    expect(SlateEntry::where('user_id', $member->id)->sole()->tiebreaker_total)->toBe(55);

    expect(fn () => app(EnterTiebreaker::class)->handle($member, $slate, 500))
        ->toThrow(InvalidArgumentException::class);

    $slate->tiebreakerGame->game->update(['status' => 'in']);
    expect(fn () => app(EnterTiebreaker::class)->handle($member, $slate->fresh(), 44))
        ->toThrow(PickLocked::class);
});

it('scales the tiebreaker answer to its question', function () {
    [$member, , $slate] = pickemLiveBoard();
    $slate->loadMissing('tiebreakerGame.game');

    // 500 is nonsense as combined points...
    expect(fn () => app(EnterTiebreaker::class)->handle($member, $slate, 500))
        ->toThrow(InvalidArgumentException::class);

    // ...and a real answer once the week asks about passing yards.
    $slate->update([
        'tiebreaker_metric' => 'passing_yards',
        'tiebreaker_team_id' => $slate->tiebreakerGame->game->home_team_id,
    ]);
    app(EnterTiebreaker::class)->handle($member, $slate->fresh(), 500);

    expect(SlateEntry::where('user_id', $member->id)->sole()->tiebreaker_total)->toBe(500);
});

// ---------------------------------------------------------------- privacy

it('shows others\' picks only after kickoff — your own always', function () {
    [$member, $group, $slate] = pickemLiveBoard();
    $rival = User::factory()->create(['handle' => 'rival']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $rival->id]);

    $slateGame = $slate->games()->with('game')->first();
    app(MakePick::class)->handle($member, $slateGame, $slateGame->game->home_team_id);
    app(MakePick::class)->handle($rival, $slateGame->fresh(), $slateGame->game->away_team_id);

    // Before kickoff: I see mine, not theirs.
    $visible = Pick::visibleTo($member)->pluck('user_id');
    expect($visible)->toContain($member->id)->not->toContain($rival->id);

    // After kickoff: the whole room shows its cards.
    $this->travelTo('2026-09-05 19:30:01');
    expect(Pick::visibleTo($member)->pluck('user_id'))
        ->toContain($member->id)
        ->toContain($rival->id);
});

// ------------------------------------------- screen (the clubhouse surface)

it('renders the surface for a member: sides, frozen numbers, the question', function () {
    [$member, $group] = pickemLiveBoard();

    Livewire::actingAs($member)->test('group', ['group' => $group])
        ->assertSee($group->name)
        ->assertSee('Shotgun')
        ->assertSee('-6.5')
        ->assertSee('+6.5')
        ->assertSee('Tiebreaker');
});

it('picks from the surface and marks the row', function () {
    [$member, $group, $slate] = pickemLiveBoard();
    $slateGame = $slate->games()->with('game')->first();

    Livewire::actingAs($member)->test('group', ['group' => $group])
        ->call('pick', $slateGame->id, $slateGame->game->home_team_id);

    expect(Pick::where([
        'user_id' => $member->id,
        'slate_game_id' => $slateGame->id,
        'picked_team_id' => $slateGame->game->home_team_id,
    ])->exists())->toBeTrue();
});

it('walks a handleless member through the claim, then opens the surface', function () {
    [, $group] = pickemLiveBoard();
    $handleless = User::factory()->handleless()->create(['admin' => true]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $handleless->id]);

    Livewire::actingAs($handleless)->test('group', ['group' => $group])
        ->assertSee('Claim your handle')
        ->set('handle', 'freshmeat')
        ->call('claim')
        ->assertDontSee('Claim your handle');

    expect($handleless->fresh()->handle)->toBe('freshmeat');
});

it('locks a kicked-off row on the surface', function () {
    [$member, $group, $slate] = pickemLiveBoard();

    // Locked BY CLOCK, feed still quiet: the card says "Locked" plainly.
    // (A game live by feed shows the Live pulse instead — the state a
    // reader actually scans for once play starts.)
    $slate->games()->with('game')->first()->game->update(['kickoff_at' => now()->subHour()]);

    Livewire::actingAs($member)->test('group', ['group' => $group])->assertSee('Locked');
});

it('keeps the coming-soon promise for everyone outside the flag', function () {
    $outside = User::factory()->create();

    Livewire::actingAs($outside)->test('lobby')
        ->assertSee('Coming soon')
        ->assertSee('Weekly slates');

    $this->post(route('logout'));
    $this->get(route('pickem.lobby'))->assertOk()->assertSee('Coming soon');
});
