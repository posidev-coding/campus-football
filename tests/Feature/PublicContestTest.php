<?php

use App\Actions\JoinGroup;
use App\Actions\LeaveGroup;
use App\Actions\SpawnPublicContest;
use App\Enums\ContestMode;
use App\Exceptions\ContestFull;
use App\Models\Contest;
use App\Models\GameOdd;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\User;
use Livewire\Livewire;

/*
 * TRANSIENT PUBLIC ROOMS: per-mode weekly instances with a seat cap, a
 * cloned house slate, no commissioner, and spawn-on-fill through the
 * atomic filled_at claim. The lobby lists only OPEN rooms; a room's URL
 * outlives its week.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

/** A week with enough suggestible games for any mode's standard slate. */
function publicContestWeek(): array
{
    [$season, $week] = pickemSeasonWeek();

    foreach (range(1, 16) as $i) {
        $game = pickemGame($season, $week);
        pickemOdd($game);
        $game->predictor()->create(['matchup_quality' => 95 - $i]);
    }

    return [$season, $week];
}

it('spawns a complete room: named, capped, one contest, published slate, no commissioner', function () {
    [, $week] = publicContestWeek();

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);

    expect($room)->not->toBeNull()
        // The pool's first play call — a NAME, no date, no "Open", no
        // serial: the mode chip and the floor's week say the boring facts.
        ->and($room->name)->toBe('Hail Mary')
        ->and($room->kind)->toBe(Group::KIND_LOBBY)
        ->and($room->week_id)->toBe($week->id)
        ->and($room->member_cap)->toBe(Group::DEFAULT_LOBBY_CAP)
        // The house runs these rooms — nobody holds the gavel.
        ->and($room->memberships()->count())->toBe(0);

    $slate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $room->id))->sole();
    expect($slate->status)->toBe(Slate::PUBLISHED)
        ->and($slate->games()->count())->toBe(10)
        ->and($slate->tiebreaker_slate_game_id)->not->toBeNull();
});

it('clones the sibling\'s FROZEN slate, immune to market drift', function () {
    [, $week] = publicContestWeek();

    $first = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    $firstSlate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $first->id))->sole();

    // The market moves after Room 1 froze...
    GameOdd::query()->where('phase', GameOdd::CURRENT)->update(['spread' => -20.5]);

    $second = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    $secondSlate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $second->id))->sole();

    // ...and Room 2 plays Room 1's exact lines anyway — comparable rooms,
    // by construction.
    expect($second->name)->toBe('Flea Flicker')
        ->and($secondSlate->games()->orderBy('position')->pluck('spread', 'game_id')->all())
        ->toBe($firstSlate->games()->orderBy('position')->pluck('spread', 'game_id')->all())
        ->and($secondSlate->tiebreaker_metric)->toBe($firstSlate->tiebreaker_metric);
});

it('fills, claims once, and spawns the next room', function () {
    [, $week] = publicContestWeek();

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    $room->update(['member_cap' => 2]);

    app(JoinGroup::class)->handle(User::factory()->create(), $room);
    expect($room->fresh()->filled_at)->toBeNull();

    app(JoinGroup::class)->handle(User::factory()->create(), $room->fresh());

    // The fill stamped the claim and provisioned Room 2.
    expect($room->fresh()->filled_at)->not->toBeNull()
        ->and(Group::where('kind', Group::KIND_LOBBY)->where('week_id', $week->id)->count())->toBe(2);

    // A third joiner bounces off the cap — and lands in the fresh room.
    $third = User::factory()->create();
    expect(fn () => app(JoinGroup::class)->handle($third, $room->fresh()))
        ->toThrow(ContestFull::class);

    $next = Group::where('kind', Group::KIND_LOBBY)->where('week_id', $week->id)
        ->whereKeyNot($room->id)->sole();
    app(JoinGroup::class)->handle($third, $next);
    expect($next->memberships()->count())->toBe(1);
});

it('refuses a seat in a week already being played', function () {
    [, $week] = publicContestWeek();

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    $slate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $room->id))->sole();

    // Every game kicked: a seat here couldn't pick a thing.
    foreach ($slate->games()->with('game')->get() as $slateGame) {
        $slateGame->game->update(['kickoff_at' => now()->subHour()]);
    }

    expect(fn () => app(JoinGroup::class)->handle(User::factory()->create(), $room))
        ->toThrow(ContestFull::class);
});

it('keeps at least one open room per catalog entry through the sweep, idempotently', function () {
    publicContestWeek();

    $this->artisan('pickem:open-lobbies')->assertSuccessful();

    /*
     * The three standard rooms plus every specialty this fixture can seat:
     * the flash card, the kicker room, and the small-table Woodshed.
     * Ranked, primetime and the conference family have no qualifying games
     * here, and feasibility keeps them off the floor.
     */
    $rooms = Group::query()->where('kind', Group::KIND_LOBBY)->get();
    expect($rooms)->toHaveCount(6)
        ->and($rooms->whereNull('flavor')->pluck('id')->pipe(fn ($ids) => Contest::query()->whereIn('group_id', $ids)->pluck('mode')->all()))
        ->toEqualCanonicalizing([ContestMode::Classic, ContestMode::Tiered, ContestMode::Woodshed])
        ->and($rooms->pluck('flavor')->filter()->values()->all())
        ->toEqualCanonicalizing(['two_minute', 'upset_alley', 'back_porch']);

    // The shelf is stocked; a second pass adds nothing.
    $this->artisan('pickem:open-lobbies')->assertSuccessful();
    expect(Group::query()->where('kind', Group::KIND_LOBBY)->count())->toBe(6);
});

it('lists only OPEN rooms on the lobby floor', function () {
    [, $week] = publicContestWeek();
    $viewer = pickemAdmin();

    $open = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);

    // A settled room leaves the inventory; its URL keeps working.
    $done = app(SpawnPublicContest::class)->handle(ContestMode::Tiered, $week);
    Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $done->id))
        ->update(['status' => Slate::SETTLED, 'settled_at' => now()]);

    Livewire::actingAs($viewer)->test('lobby')
        ->assertSee($open->name)
        ->assertSee('0 of 20')
        ->assertDontSee($done->name);

    $this->actingAs($viewer)->get(route('pickem.room', $done))->assertOk();
});

it('wears its own address: rooms at /contests, groups at /groups', function () {
    [, $week] = publicContestWeek();
    $viewer = pickemAdmin();

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    $group = Group::factory()->create();
    GroupMember::factory()->commissioner()->create(['group_id' => $group->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->get(route('pickem.group', $room))
        ->assertRedirect(route('pickem.room', $room));
    $this->actingAs($viewer)->get(route('pickem.room', $group))
        ->assertRedirect(route('pickem.group', $group));
});

it('shows a room its week, its seats, and no Season tab', function () {
    [, $week] = publicContestWeek();
    $viewer = pickemAdmin();

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    app(JoinGroup::class)->handle($viewer, $room);

    Livewire::actingAs($viewer)->test('group', ['group' => $room->fresh()])
        ->assertSee('Week 1')
        ->assertSee('1 of 20 seats')
        ->assertSee('Slate')
        ->assertSee('Members')
        ->assertDontSee('Season');
});

it('answers a full room in Voice, from the screen', function () {
    [, $week] = publicContestWeek();

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    $room->update(['member_cap' => 1]);
    app(JoinGroup::class)->handle(User::factory()->create(), $room);

    Livewire::actingAs(pickemAdmin())->test('group', ['group' => $room->fresh()])
        ->call('join')
        ->assertHasErrors('group');
});

it('lets a room empty out without dying — the URL is history', function () {
    [, $week] = publicContestWeek();
    $member = User::factory()->create();

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    app(JoinGroup::class)->handle($member, $room);
    app(LeaveGroup::class)->handle($member, $room);

    expect(Group::find($room->id))->not->toBeNull();
});

it('publishes the winner on a settled room\'s wall', function () {
    [, $week] = publicContestWeek();
    $viewer = pickemAdmin();

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    app(JoinGroup::class)->handle($viewer, $room);

    $slate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $room->id))->sole();
    $slate->entries()->create(['user_id' => $viewer->id, 'final_points' => 9, 'won' => true]);
    $slate->update(['status' => Slate::SETTLED, 'settled_at' => now()]);

    Livewire::actingAs($viewer)->test('group', ['group' => $room->fresh()])
        ->assertSee('@'.$viewer->handle);
});
