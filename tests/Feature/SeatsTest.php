<?php

use App\Actions\JoinGroup;
use App\Actions\SpawnPublicContest;
use App\Enums\ContestMode;
use App\Models\Contest;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Support\Cadence;
use App\Support\Lobby;
use App\Support\Seats;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * THE SEATS READ — every seat the viewer holds, partitioned the way the
 * product is: private groups, this Saturday's rooms, played rooms, and
 * the always-open tables. My Picks' cards() and the group switcher on
 * both pick'em screens stand on this one read, which is the whole reason
 * it exists: two reads answering "which groups am I in" is how a menu
 * starts disagreeing with the page under it.
 */

/** A week with enough suggestible games for any mode's standard slate. */
function seatsWeek(): array
{
    [$season, $week] = pickemSeasonWeek();

    foreach (range(1, 16) as $i) {
        $game = pickemGame($season, $week);
        pickemOdd($game);
        $game->predictor()->create(['matchup_quality' => 95 - $i]);
    }

    return [$season, $week];
}

it('partitions every seat by kind, with the groups in name order', function () {
    $this->travelTo('2026-09-02 12:00:00');

    $viewer = pickemAdmin();
    [, $week] = seatsWeek();

    foreach (['The Back Porch', 'Rocky Top Rejects'] as $name) {
        $group = Group::factory()->create(['name' => $name]);
        GroupMember::factory()->commissioner()->create(['group_id' => $group->id, 'user_id' => $viewer->id]);
        Contest::factory()->create(['group_id' => $group->id]);
    }

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    app(JoinGroup::class)->handle($viewer, $room);

    $table = Group::factory()->lobby()->create(['name' => 'The Big Lobby', 'week_id' => null]);
    GroupMember::factory()->create(['group_id' => $table->id, 'user_id' => $viewer->id]);

    $seats = Seats::for($viewer->fresh());

    expect($seats->hasSeats())->toBeTrue()
        ->and($seats->privateGroups()->pluck('name')->all())->toBe(['Rocky Top Rejects', 'The Back Porch'])
        ->and($seats->rooms()->pluck('id')->all())->toBe([$room->id])
        ->and($seats->pastRooms())->toBeEmpty()
        ->and($seats->tables()->pluck('id')->all())->toBe([$table->id])
        // The pivot rides every seat: the card query reads the role off it.
        ->and($seats->privateGroups()->first()->pivot->role)->toBe(GroupMember::COMMISSIONER)
        ->and($seats->privateGroups()->first()->memberships_count)->toBe(1);
});

it('files a room under past once its week is over', function () {
    $this->travelTo('2026-09-02 12:00:00');

    $viewer = pickemAdmin();
    [$season, $week] = seatsWeek();
    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    app(JoinGroup::class)->handle($viewer, $room);

    $gone = Week::factory()->create(['season_id' => $season->id, 'number' => 0]);
    $room->update(['week_id' => $gone->id]);
    Slate::query()->whereIn('contest_id', $room->contests()->pluck('id'))->update(['week_id' => $gone->id]);

    $seats = Seats::for($viewer->fresh());

    expect($seats->pastRooms()->pluck('id')->all())->toBe([$room->id])
        ->and($seats->rooms())->toBeEmpty();
});

it('files a room under past off its OWN Saturday inside a split week, and labels the week a fan would', function () {
    /*
     * 2026's Week 1 holds two Saturdays, 8/29 and 9/5, so a room that
     * played 8/29 still satisfies `week_id === weekId` on the Tuesday
     * after. Past is read off the room's own Saturday against the one
     * being sold — and the label follows the same Saturday: Week 0 on
     * the first card, Week 1 on the main one.
     */
    $this->travelTo('2026-08-26 12:00:00');

    $viewer = pickemAdmin();
    [, $week] = splitPickemWeek();

    foreach (Game::query()->whereNotNull('kickoff_at')->get() as $game) {
        pickemOdd($game);
        $game->predictor()->create(['matchup_quality' => 90.0]);
    }

    $played = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, Carbon::parse('2026-08-29'));
    app(JoinGroup::class)->handle($viewer, $played);

    $before = Seats::for($viewer->fresh());

    expect($before->weekLabel())->toBe('Week 0')
        ->and($before->rooms()->pluck('id')->all())->toBe([$played->id]);

    $this->travelTo('2026-09-01 12:00:00');
    CfbCalendar::flush();
    Cadence::flush();

    $selling = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, Carbon::parse('2026-09-05'));
    app(JoinGroup::class)->handle($viewer, $selling);

    // Same week id on both — which is the whole trap.
    expect($played->week_id)->toBe($selling->week_id);

    $after = Seats::for($viewer->fresh());

    expect($after->weekLabel())->toBe('Week 1')
        ->and($after->rooms()->pluck('id')->all())->toBe([$selling->id])
        ->and($after->pastRooms()->pluck('id')->all())->toBe([$played->id])
        ->and($after->roomSaturdays()->get($played->id))->toBe('2026-08-29')
        ->and($after->roomSaturdays()->get($selling->id))->toBe('2026-09-05');
});

it('never files a room with no slate under past', function () {
    // Its card never landed or was taken away — a different sentence,
    // and never inferred from a missing row.
    $this->travelTo('2026-09-02 12:00:00');

    $viewer = pickemAdmin();
    [, $week] = seatsWeek();
    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    app(JoinGroup::class)->handle($viewer, $room);

    Slate::query()->whereIn('contest_id', $room->contests()->pluck('id'))->delete();

    $seats = Seats::for($viewer->fresh());

    expect($seats->isPast($room->fresh()))->toBeFalse()
        ->and($seats->rooms()->pluck('id')->all())->toBe([$room->id]);
});

it('has no week label when the calendar has no week', function () {
    $viewer = pickemAdmin();
    $group = Group::factory()->create();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $viewer->id]);

    $seats = Seats::for($viewer->fresh());

    expect($seats->week())->toBeNull()
        ->and($seats->weekLabel())->toBeNull()
        ->and($seats->privateGroups()->pluck('id')->all())->toBe([$group->id]);
});

it('counts the open rooms exactly as the Lobby does', function () {
    $this->travelTo('2026-09-02 12:00:00');

    $viewer = pickemAdmin();
    [, $week] = seatsWeek();

    app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    $seated = app(SpawnPublicContest::class)->handle(ContestMode::Tiered, $week);
    app(JoinGroup::class)->handle($viewer, $seated);

    $seats = Seats::for($viewer->fresh());

    expect($seats->openCount())->toBe(1)
        ->and($seats->openCount())->toBe(Lobby::openRoomCount($viewer));
});

it('is empty and query-free for a guest', function () {
    DB::enableQueryLog();

    $seats = Seats::for(null);

    expect($seats->hasSeats())->toBeFalse()
        ->and($seats->privateGroups())->toBeEmpty()
        ->and($seats->rooms())->toBeEmpty()
        ->and($seats->tables())->toBeEmpty()
        ->and(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
});

it('reads the groups once and the rest lazily', function () {
    $viewer = pickemAdmin();
    $group = Group::factory()->create();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $viewer->id]);

    $viewer = $viewer->fresh();

    DB::enableQueryLog();

    $seats = Seats::for($viewer);
    $seats->privateGroups();
    $seats->tables();

    // The groups, and nothing else: no week, no Saturday, no count until
    // somebody asks for one.
    expect(DB::getQueryLog())->toHaveCount(1)
        ->and(DB::getQueryLog()[0]['query'])->toContain('pivot_role');

    DB::disableQueryLog();
});
