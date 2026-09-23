<?php

use App\Actions\ChangeGroupMode;
use App\Actions\PublishSlate;
use App\Enums\ContestMode;
use App\Exceptions\ModeChangeBlocked;
use App\Exceptions\NotGroupCommissioner;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\User;
use App\Notifications\GroupModeChanged;
use App\Support\Voice;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The ONE mode pivot a group gets per season: commissioner-only, blocked
 * while a published week is in flight (the engine reads mode at grade
 * time), stamped so it can never happen twice, and ANNOUNCED — the note
 * is the action's side effect, not a courtesy.
 */

it('pivots the mode once: stamp set, draft reset, the group told', function () {
    Notification::fake();

    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $member = User::factory()->create(['admin' => true]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    // A draft built to Shotgun's shape must not survive into Triple
    // Option — it is reset for refill, the row kept.
    $draft = pickemDraftSlate($contest);
    expect($draft->games()->count())->toBe(10);

    app(ChangeGroupMode::class)->handle($commissioner, $group, ContestMode::Tiered);

    $contest->refresh();
    $draft->refresh();

    expect($contest->mode)->toBe(ContestMode::Tiered)
        ->and($contest->mode_changed_at)->not->toBeNull()
        ->and($draft->status)->toBe(Slate::DRAFT)
        ->and($draft->games()->count())->toBe(0)
        ->and($draft->tiebreaker_slate_game_id)->toBeNull()
        ->and($draft->tiebreaker_metric)->toBeNull();

    // The member hears about it; the commissioner who pulled the lever
    // does not get a note about their own decision.
    Notification::assertSentTo($member, GroupModeChanged::class);
    Notification::assertNotSentTo($commissioner, GroupModeChanged::class);
});

it('is once per season — the stamp is the law', function () {
    Notification::fake();

    [$commissioner, $group] = pickemContest(ContestMode::Classic);
    pickemSeasonWeek();

    app(ChangeGroupMode::class)->handle($commissioner, $group, ContestMode::Tiered);

    expect(fn () => app(ChangeGroupMode::class)->handle($commissioner, $group, ContestMode::Classic))
        ->toThrow(ModeChangeBlocked::class);
});

it('refuses while a published week is in flight, and relents once it settles', function () {
    Notification::fake();

    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    expect(fn () => app(ChangeGroupMode::class)->handle($commissioner, $group, ContestMode::Tiered))
        ->toThrow(ModeChangeBlocked::class);

    // The engine read Shotgun when it graded this week; once official,
    // the pivot window opens.
    $slate->update(['status' => Slate::SETTLED, 'settled_at' => now()]);

    $contest = app(ChangeGroupMode::class)->handle($commissioner, $group, ContestMode::Tiered);
    expect($contest->mode)->toBe(ContestMode::Tiered);
});

it('holds the gates: authority and a real change', function () {
    Notification::fake();

    [$commissioner, $group] = pickemContest(ContestMode::Classic);
    pickemSeasonWeek();
    $member = User::factory()->create();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    expect(fn () => app(ChangeGroupMode::class)->handle($member, $group, ContestMode::Tiered))
        ->toThrow(NotGroupCommissioner::class)
        ->and(fn () => app(ChangeGroupMode::class)->handle($commissioner, $group, ContestMode::Classic))
        ->toThrow(InvalidArgumentException::class);

    // The Woodshed is a real pivot target now that its rules landed.
    $contest = app(ChangeGroupMode::class)->handle($commissioner, $group, ContestMode::Woodshed);
    expect($contest->mode)->toBe(ContestMode::Woodshed);
});

it('drives the pivot from the clubhouse modal, and answers the blocked lever in Voice', function () {
    Notification::fake();

    [$commissioner, $group] = pickemContest(ContestMode::Classic);
    pickemSeasonWeek();

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSee('Change the game')
        // Three live modes: the radiogroup offers BOTH other doors.
        ->assertSee('Triple Option')
        ->assertSee('Woodshed')
        ->call('choosePivot', 'tiered')
        ->call('changeMode')
        ->assertSee('New mode: Triple Option');

    expect($group->contests()->first()->mode)->toBe(ContestMode::Tiered);

    // The lever is spent: the modal's second pull answers in Voice.
    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->call('choosePivot', 'classic')
        ->call('changeMode')
        ->assertHasErrors('mode')
        ->assertSee(Voice::line('mode.change.blocked.used'));
});

it('shows no lever to a plain member', function () {
    Notification::fake();

    [, $group] = pickemContest(ContestMode::Classic);
    $member = User::factory()->create(['admin' => true]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    Livewire::actingAs($member)->test('group', ['group' => $group])
        ->assertDontSee('Change the game');
});

it('keeps the clubhouse standing when a Monday pivot leaves last Saturday\'s untiered card on screen', function () {
    /*
     * Sunday and Monday still show the Saturday just played
     * (Cadence::currentSaturday), and the pick surface prices every card
     * with the contest's CURRENT engine. A settled Shotgun card has no
     * tiers, and a tiered engine's pointsFor() has no arm for a null tier
     * — so the first day of the pivot window rendered a 500 to every member
     * who opened the slate tab. Found while planning the private-group
     * merge, whose run day is exactly that Monday.
     */
    Notification::fake();

    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $slate->refresh()->update(['status' => Slate::SETTLED, 'settled_at' => now()]);

    // Monday noon Eastern: the clubhouse is still on Saturday 9/5.
    $this->travelTo('2026-09-07 16:00:00');

    app(ChangeGroupMode::class)->handle($commissioner, $group, ContestMode::Woodshed);

    $member = User::factory()->create(['admin' => true]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    $html = Livewire::actingAs($member)->test('group', ['group' => $group])
        ->set('view', 'slate')
        ->html();

    expect($html)->toContain('wire:key="slate-'.$slate->id.'-pick-');
});
