<?php

use App\Actions\LockPick;
use App\Actions\MakePick;
use App\Actions\PublishSlate;
use App\Enums\ContestMode;
use App\Exceptions\HandleRequired;
use App\Exceptions\NotGroupMember;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\PickLocked;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\User;
use App\Support\Voice;

/*
 * The Lock wager's one door. Every gate MakePick holds, plus the wager's
 * own three: the mode must offer a Lock, only the featured game takes it,
 * and there must be a pick to stake. Unstaking is the same door.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

/**
 * A published Woodshed slate with a seated member — returns the member,
 * the featured slate game, and a plain (unfeatured) slate game.
 */
function lockableWoodshed(): array
{
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Woodshed);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $slate = $slate->fresh();

    $member = User::factory()->create(['handle' => 'stakes']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    $featured = $slate->games()->with('game')->find($slate->tiebreaker_slate_game_id);
    $plain = $slate->games()->with('game')->whereKeyNot($featured->id)->orderBy('position')->first();

    return [$member, $featured, $plain, $slate];
}

it('stakes and pulls the Lock on the featured game, before kickoff', function () {
    [$member, $featured] = lockableWoodshed();

    $pick = app(MakePick::class)->handle($member, $featured, $featured->game->home_team_id);
    // updateOrCreate leaves DB-defaulted columns off the in-memory model.
    expect($pick->refresh()->locked)->toBeFalse();

    app(LockPick::class)->handle($member, $featured, true);
    expect($pick->fresh()->locked)->toBeTrue();

    // Changing your mind is the same door.
    app(LockPick::class)->handle($member, $featured, false);
    expect($pick->fresh()->locked)->toBeFalse();
});

it('holds every gate in MakePick\'s order', function () {
    [$member, $featured] = lockableWoodshed();
    app(MakePick::class)->handle($member, $featured, $featured->game->home_team_id);

    $unverified = User::factory()->unverified()->create(['handle' => 'ghost']);
    expect(fn () => app(LockPick::class)->handle($unverified, $featured, true))
        ->toThrow(PickemParticipationGated::class);

    $handleless = User::factory()->create(['handle' => null]);
    expect(fn () => app(LockPick::class)->handle($handleless, $featured, true))
        ->toThrow(HandleRequired::class);

    $outsider = User::factory()->create(['handle' => 'outsider']);
    expect(fn () => app(LockPick::class)->handle($outsider, $featured, true))
        ->toThrow(NotGroupMember::class);
});

it('freezes the wager with the game', function () {
    [$member, $featured] = lockableWoodshed();
    app(MakePick::class)->handle($member, $featured, $featured->game->home_team_id);

    $this->travelTo('2026-09-05 20:00:00');

    expect(fn () => app(LockPick::class)->handle($member, $featured, true))
        ->toThrow(PickLocked::class);
});

it('takes the Lock only on the featured game, only in the Woodshed, only on a real pick', function () {
    [$member, $featured, $plain] = lockableWoodshed();

    // No pick yet: the toggle never invents a side.
    expect(fn () => app(LockPick::class)->handle($member, $featured, true))
        ->toThrow(InvalidArgumentException::class, 'pick a side first');

    // A pick on a plain game cannot be staked.
    app(MakePick::class)->handle($member, $plain, $plain->game->home_team_id);
    expect(fn () => app(LockPick::class)->handle($member, $plain, true))
        ->toThrow(InvalidArgumentException::class, 'featured game');

    // The other modes have no Lock at all.
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Tiered);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $tieredMember = User::factory()->create(['handle' => 'tiered']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $tieredMember->id]);
    $tieredFeatured = $slate->fresh()->games()->with('game')->find($slate->fresh()->tiebreaker_slate_game_id);
    app(MakePick::class)->handle($tieredMember, $tieredFeatured, $tieredFeatured->game->home_team_id);

    expect(fn () => app(LockPick::class)->handle($tieredMember, $tieredFeatured, true))
        ->toThrow(InvalidArgumentException::class, 'no Lock');
});

it('wears the wager on the surface: the Bear announced, his paw on cards, one toggle on the featured game', function () {
    [$member, $featured, , $slate] = lockableWoodshed();
    $group = Group::query()->findOrFail($slate->contest->group_id);

    $surface = Livewire\Livewire::actingAs($member)->test('group', ['group' => $group]);

    // The Bear's banner (week 1 rides the dogs), his paw on the cards,
    // and exactly ONE Lock footer — the featured card's.
    $surface->assertSee('The Bear rides the underdogs this week')
        ->assertSeeHtml('data-bear')
        ->assertSee('Pick a side to stake the Lock');

    expect(substr_count($surface->html(), 'data-lock-toggle'))->toBe(1);

    // Pick, then stake — the whole flow through the surface's own wiring.
    $surface->call('pick', $featured->id, $featured->game->home_team_id)
        ->call('lockPick', $featured->id, true)
        ->assertSee('Locked in');

    expect(Pick::where('user_id', $member->id)->sole()->locked)->toBeTrue();

    // A kicked game puts the Bear in the This-week standings.
    $featured->game->update(['home_score' => 28, 'away_score' => 7, 'status' => 'in']);

    Livewire\Livewire::actingAs($member)->test('group', ['group' => $group])
        ->assertSee('This week')
        ->assertSee('The Bear');
});

it('keeps the wager furniture off the other modes\' surfaces', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Tiered);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $member = User::factory()->create(['handle' => 'plainweek']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    $html = Livewire\Livewire::actingAs($member)->test('group', ['group' => $group])->html();

    expect($html)->not->toContain('data-lock-toggle')
        ->not->toContain('data-bear')
        ->not->toContain('The Bear rides');
});

it('shows the founders\' stakes in the builder\'s tiers station', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Woodshed);
    pickemDraftSlate($contest);

    Livewire\Livewire::actingAs($commissioner)->test('slate-builder', ['group' => $group])
        ->set('step', 'tiers')
        ->assertSee('Tier 1 pays 8')
        ->assertSee('Tier 2 pays 6')
        ->assertSee('Tier 3 pays 4');
});

describe('the surface absorbs every refusal', function () {
    // Everything LockPick can throw is a notice or a deliberate silent
    // re-render in MakesPicks::lockPick — an uncaught throw is a raw 500
    // on the toggle. Completing each call is half the test.
    it('tells a removed member, instead of a 500', function () {
        [$member, $featured, , $slate] = lockableWoodshed();
        $group = Group::query()->findOrFail($slate->contest->group_id);
        app(MakePick::class)->handle($member, $featured, $featured->game->home_team_id);

        $surface = Livewire\Livewire::actingAs($member)->test('group', ['group' => $group]);

        GroupMember::query()->where(['group_id' => $group->id, 'user_id' => $member->id])->delete();

        $surface->call('lockPick', $featured->id, true)
            ->assertSet('notice', Voice::line('talk.not_member', for: $member));

        expect(Pick::query()->where('user_id', $member->id)->sole()->refresh()->locked)->toBeFalse();
    });

    it('points a handleless toggle at the claim', function () {
        [, $featured, , $slate] = lockableWoodshed();
        $group = Group::query()->findOrFail($slate->contest->group_id);
        $handleless = User::factory()->create(['handle' => null]);
        GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $handleless->id]);

        Livewire\Livewire::actingAs($handleless)->test('group', ['group' => $group])
            ->call('lockPick', $featured->id, true)
            ->assertSet('notice', Voice::line('picks.claim.body', for: $handleless));
    });

    it('shrugs off a stale id and an unfeatured game silently', function () {
        [$member, $featured, $plain, $slate] = lockableWoodshed();
        $group = Group::query()->findOrFail($slate->contest->group_id);
        app(MakePick::class)->handle($member, $featured, $featured->game->home_team_id);
        app(MakePick::class)->handle($member, $plain, $plain->game->home_team_id);

        $surface = Livewire\Livewire::actingAs($member)->test('group', ['group' => $group]);

        // Stale slate-game id after a rebuild: ModelNotFoundException.
        $surface->call('lockPick', 999999, true)->assertSet('notice', null);

        // Only the featured game takes the Lock: InvalidArgumentException.
        $surface->call('lockPick', $plain->id, true)->assertSet('notice', null);

        expect(Pick::query()->where('user_id', $member->id)->where('locked', true)->exists())->toBeFalse();
    });
});

it('refuses a draft slate', function () {
    [, , $contest] = pickemContest(ContestMode::Woodshed);
    $slate = pickemDraftSlate($contest);
    $member = User::factory()->create(['handle' => 'early']);
    GroupMember::factory()->create(['group_id' => $contest->group_id, 'user_id' => $member->id]);

    $featured = $slate->games()->with('game')->find($slate->tiebreaker_slate_game_id);

    expect($slate->status)->toBe(Slate::DRAFT)
        ->and(fn () => app(LockPick::class)->handle($member, $featured, true))
        ->toThrow(InvalidArgumentException::class, 'not published');
});
