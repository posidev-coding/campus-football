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
use App\Support\Voice;
use Livewire\Livewire;

/*
 * Phase 5 slice 5: picking. The lock is temporal and per game, privacy
 * until lock is a query rule, a missed pick stays an absent row, and every
 * gate lives in MakePick — the sheet's disabled buttons are decoration.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

/** A published slate plus a verified, handled member ready to pick. */
function pickemLiveSlate(): array
{
    [$commissioner, $group, $contest] = pickemContest();
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    $member = User::factory()->create(['handle' => 'picksix', 'admin' => true]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    return [$member, $group, $slate->fresh()];
}

// ---------------------------------------------------------------- MakePick

it('records a pick, seats the entry, and pays entry XP once per slate', function () {
    [$member, , $slate] = pickemLiveSlate();
    [$first, $second] = $slate->games()->with('game')->orderBy('position')->take(2)->get();

    app(MakePick::class)->handle($member, $first, $first->game->home_team_id);
    app(MakePick::class)->handle($member, $second, $second->game->away_team_id);

    expect(Pick::where('user_id', $member->id)->count())->toBe(2)
        ->and(SlateEntry::where(['slate_id' => $slate->id, 'user_id' => $member->id])->count())->toBe(1)
        // Two picks, ONE keyed entry grant — the wallet index is the cap.
        ->and(WalletEntry::where('user_id', $member->id)->where('reason', 'pickem-entered')->count())->toBe(1);
});

it('lets a mind change until kickoff, in the same row', function () {
    [$member, , $slate] = pickemLiveSlate();
    $slateGame = $slate->games()->with('game')->first();

    app(MakePick::class)->handle($member, $slateGame, $slateGame->game->home_team_id);
    app(MakePick::class)->handle($member, $slateGame, $slateGame->game->away_team_id);

    $picks = Pick::where('user_id', $member->id)->get();
    expect($picks)->toHaveCount(1)
        ->and($picks->first()->picked_team_id)->toBe($slateGame->game->away_team_id);
});

it('locks at kickoff by clock, and early by feed', function () {
    [$member, , $slate] = pickemLiveSlate();
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
    [$member, $group, $slate] = pickemLiveSlate();
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
    [$member, , $slate] = pickemLiveSlate();

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
    [$member, , $slate] = pickemLiveSlate();
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
    [$member, $group, $slate] = pickemLiveSlate();
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
    [$member, $group] = pickemLiveSlate();

    Livewire::actingAs($member)->test('group', ['group' => $group])
        ->assertSee($group->name)
        ->assertSee('Shotgun')
        ->assertSee('-6.5')
        ->assertSee('+6.5')
        ->assertSee('Tiebreaker');
});

it('picks from the surface and marks the row', function () {
    [$member, $group, $slate] = pickemLiveSlate();
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
    [, $group] = pickemLiveSlate();
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
    [$member, $group, $slate] = pickemLiveSlate();

    // Locked BY CLOCK, feed still quiet: the card says "Locked" plainly.
    // (A game live by feed shows the Live pulse instead — the state a
    // reader actually scans for once play starts.)
    $slate->games()->with('game')->first()->game->update(['kickoff_at' => now()->subHour()]);

    Livewire::actingAs($member)->test('group', ['group' => $group])->assertSee('Locked');
});

describe('the surface absorbs every refusal', function () {
    /*
     * Everything MakePick and EnterTiebreaker can throw is either a notice
     * or a deliberate silent re-render — anything uncaught is a raw 500 on
     * a tap. Each case below merely COMPLETING the call is half the test.
     */
    it('tells a member the commissioner removed mid-session, instead of a 500', function () {
        [$member, $group, $slate] = pickemLiveSlate();
        $slateGame = $slate->games()->with('game')->first();

        $surface = Livewire::actingAs($member)->test('group', ['group' => $group]);

        GroupMember::query()->where(['group_id' => $group->id, 'user_id' => $member->id])->delete();

        $surface->call('pick', $slateGame->id, $slateGame->game->home_team_id)
            ->assertSet('notice', Voice::line('talk.not_member', for: $member));

        expect(Pick::query()->where('user_id', $member->id)->exists())->toBeFalse();
    });

    it('points a handleless tap at the claim', function () {
        [, $group, $slate] = pickemLiveSlate();
        $handleless = User::factory()->handleless()->create(['admin' => true]);
        GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $handleless->id]);
        $slateGame = $slate->games()->with('game')->first();

        Livewire::actingAs($handleless)->test('group', ['group' => $group])
            ->call('pick', $slateGame->id, $slateGame->game->home_team_id)
            ->assertSet('notice', Voice::line('picks.claim.body', for: $handleless));
    });

    it('shrugs off a stale slate-game id after an unpublish', function () {
        [$member, $group] = pickemLiveSlate();

        Livewire::actingAs($member)->test('group', ['group' => $group])
            ->call('pick', 999999, 1)
            ->assertSet('notice', null)
            ->assertStatus(200);
    });

    it('shrugs off a team that is not in the game', function () {
        [$member, $group, $slate] = pickemLiveSlate();
        $slateGame = $slate->games()->with('game')->first();

        Livewire::actingAs($member)->test('group', ['group' => $group])
            ->call('pick', $slateGame->id, 424242)
            ->assertSet('notice', null);

        expect(Pick::query()->where('user_id', $member->id)->exists())->toBeFalse();
    });

    it('absorbs the same refusals on the tiebreaker call', function () {
        [$member, $group, $slate] = pickemLiveSlate();

        $surface = Livewire::actingAs($member)->test('group', ['group' => $group]);

        // A stale slate id: silent, the refreshed card is the answer.
        $surface->call('saveTotal', 999999)->assertSet('notice', null);

        // An implausible answer: refused by the action, absorbed here.
        $surface->set('totals.'.$slate->id, 99999)
            ->call('saveTotal', $slate->id)
            ->assertSet('notice', null);

        expect(SlateEntry::query()->where('user_id', $member->id)->whereNotNull('tiebreaker_total')->exists())
            ->toBeFalse();

        // Removed mid-session: the tiebreaker says so too.
        GroupMember::query()->where(['group_id' => $group->id, 'user_id' => $member->id])->delete();
        $surface->set('totals.'.$slate->id, 38)
            ->call('saveTotal', $slate->id)
            ->assertSet('notice', Voice::line('talk.not_member', for: $member));
    });
});

it('keeps the coming-soon promise for everyone outside the flag', function () {
    $outside = User::factory()->create();

    Livewire::actingAs($outside)->test('lobby')
        ->assertSee('Coming soon')
        ->assertSee('Weekly slates');

    $this->post(route('logout'));
    $this->get(route('pickem.lobby'))->assertOk()->assertSee('Coming soon');
});
