<?php

use App\Actions\JoinGroup;
use App\Actions\SpawnPublicContest;
use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Enums\LobbyShelf;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\User;
use App\Support\Lobby;
use App\Support\LobbyCatalog;

/*
 * THE LOBBY'S INVENTORY READ, under the screens: what is open, what the
 * viewer already sits in, and what the Saturday could not seat.
 *
 * The fact this file exists to hold: openRoomCount() — the number the My
 * Picks teaser sells — must equal the transient rooms joinable() lists.
 * Two reads answering the same question is exactly how a teaser starts
 * lying about the door it opens, and only a parity test catches the drift.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

/** A week with enough suggestible games for any mode's standard slate. */
function lobbyRoomsWeek(): array
{
    [$season, $week] = pickemSeasonWeek();

    foreach (range(1, 16) as $i) {
        $game = pickemGame($season, $week);
        pickemOdd($game);
        $game->predictor()->create(['matchup_quality' => 95 - $i]);
    }

    return [$season, $week];
}

it('counts exactly the transient rooms it would sell', function () {
    [, $week] = lobbyRoomsWeek();
    $viewer = pickemAdmin();

    // OPEN: sold, and counted.
    $open = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    $flavored = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill);

    // SEATED: the viewer is in it, so it is not for sale and not counted.
    $seated = app(SpawnPublicContest::class)->handle(ContestMode::Tiered, $week);
    app(JoinGroup::class)->handle($viewer, $seated);

    // FILLED: claimed by the spawn-on-fill hook — gone from both.
    $filled = app(SpawnPublicContest::class)->handle(ContestMode::Woodshed, $week);
    $filled->update(['filled_at' => now()]);

    // CAP REACHED without the claim: still not a seat anyone can take.
    $capped = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::UpsetAlley);
    $capped->update(['member_cap' => 1]);
    GroupMember::factory()->create(['group_id' => $capped->id, 'user_id' => User::factory()->create()->id]);

    // WRONG SATURDAY: a room built for the other card of a split week.
    $strand = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::BackPorch);
    Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $strand->id))
        ->update(['saturday' => '2026-09-12']);

    // UNPUBLISHED: nothing to pick, so nothing to sell.
    $draft = app(SpawnPublicContest::class)->handle(ContestMode::Tiered, $week, null);
    Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $draft->id))
        ->update(['status' => Slate::DRAFT]);

    // EVERGREEN: an always-open table is not a Saturday product. It is
    // listed, deliberately, and never counted.
    $evergreen = Group::factory()->create(['kind' => Group::KIND_LOBBY, 'week_id' => null, 'member_cap' => null]);

    $joinable = Lobby::joinable($viewer);

    expect($joinable->pluck('id')->all())->toEqualCanonicalizing([$open->id, $flavored->id, $evergreen->id])
        ->and(Lobby::openRoomCount($viewer))->toBe(2)
        // The parity that matters: the teaser's number IS the transient
        // half of the list it opens.
        ->and(Lobby::openRoomCount($viewer))->toBe($joinable->filter(fn (Group $g) => $g->isRoom())->count());
});

it('flags a started room rather than dropping it, and stops counting it', function () {
    /*
     * A room closes at the FIRST kickoff, and CLOSED IS NOT GONE: openRooms()
     * keeps it so the shelf can render it locked, exactly as it keeps a room
     * the viewer is seated in. What changes is what it is for SALE — joinable()
     * rejects it, and the teaser stops counting it.
     *
     * Its own case rather than an arm of the parity test above, because rooms
     * in one week draw from a shared pool of games: kicking a game to close one
     * room closes every other room holding it, which would prove nothing about
     * the room under test.
     *
     * Both reads of the count are asserted — openRooms() filters in PHP and
     * openRoomCount() in SQL, and a teaser counting a room the list will not
     * sell is the exact drift this file exists to catch.
     */
    [, $week] = lobbyRoomsWeek();
    $viewer = pickemAdmin();

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);

    expect(Lobby::joinable($viewer)->pluck('id')->all())->toContain($room->id)
        ->and(Lobby::openRoomCount($viewer))->toBe(1);

    $opener = SlateGame::query()
        ->whereIn('slate_id', Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $room->id))->pluck('id'))
        ->orderBy('position')
        ->first();

    Game::query()->whereKey($opener->game_id)->update(['kickoff_at' => now()->subMinute()]);

    $rooms = Lobby::openRooms($viewer);

    expect($rooms->pluck('id')->all())->toContain($room->id)
        ->and(Lobby::started($rooms->firstWhere('id', $room->id)))->toBeTrue()
        ->and(Lobby::joinable($viewer)->pluck('id')->all())->not->toContain($room->id)
        ->and(Lobby::openRoomCount($viewer))->toBe(0);
});

it('lists a seated room, flagged, so a seat never reads as a closed shelf', function () {
    [, $week] = lobbyRoomsWeek();
    $viewer = pickemAdmin();

    $open = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    $seated = app(SpawnPublicContest::class)->handle(ContestMode::Tiered, $week);
    app(JoinGroup::class)->handle($viewer, $seated);

    $rooms = Lobby::openRooms($viewer);

    expect($rooms->pluck('id')->all())->toEqualCanonicalizing([$open->id, $seated->id])
        ->and(Lobby::seated($rooms->firstWhere('id', $seated->id)))->toBeTrue()
        ->and(Lobby::seated($rooms->firstWhere('id', $open->id)))->toBeFalse();

    // And to somebody else, the same room is plain merchandise.
    $stranger = pickemAdmin();
    expect(Lobby::seated(Lobby::openRooms($stranger)->firstWhere('id', $seated->id)))->toBeFalse();
});

it('shelves the open rooms in case order, dashing what the Saturday could not seat', function () {
    [, $week] = lobbyRoomsWeek();
    $viewer = pickemAdmin();

    app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill);
    app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::UpsetAlley);

    $shelves = LobbyCatalog::shelves(Lobby::openRooms($viewer));

    // Case order IS display order — and the conference shelf earns its
    // place on dashed rows alone, which is the honest answer for a
    // Saturday that could not seat one.
    expect(collect($shelves)->pluck('shelf')->all())
        ->toBe([LobbyShelf::House, LobbyShelf::QuickHits, LobbyShelf::Spotlight, LobbyShelf::Conference])
        ->and(collect(collect($shelves)->firstWhere('shelf', LobbyShelf::Conference)['rooms']))->toBeEmpty();

    $house = collect($shelves)->firstWhere('shelf', LobbyShelf::House);

    // The open Shotgun room, with the facts its row prints — off the
    // eager-loaded slate, never a fresh query.
    expect($house['rooms'])->toHaveCount(1)
        ->and($house['rooms'][0]['room']->name)->toBe('Hail Mary')
        ->and($house['rooms'][0]['mode'])->toBe(ContestMode::Classic)
        ->and($house['rooms'][0]['gameCount'])->toBe(10)
        ->and($house['rooms'][0]['seats'])->toBe(0)
        ->and($house['rooms'][0]['seated'])->toBeFalse()
        // The two tiered modes were never stocked — dashed, not silent.
        ->and(collect($house['closed'])->pluck('label')->all())
        ->toBe([ContestMode::Tiered->label(), ContestMode::Woodshed->label()]);
});

it('drops every shelf, dashes included, when the lobby is empty', function () {
    lobbyRoomsWeek();

    // No sweep has run: absence proves nothing, so the catalog stays quiet
    // rather than telling a reader thirteen rooms are impossible.
    expect(LobbyCatalog::shelves(Lobby::openRooms(pickemAdmin())))->toBe([]);
});

it('never dashes a shape the viewer is sitting in', function () {
    [, $week] = lobbyRoomsWeek();
    $viewer = pickemAdmin();

    app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    $seated = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill);
    app(JoinGroup::class)->handle($viewer, $seated);

    $shelves = LobbyCatalog::shelves(Lobby::openRooms($viewer));
    $quick = collect($shelves)->firstWhere('shelf', LobbyShelf::QuickHits);

    expect($quick['rooms'])->toHaveCount(1)
        ->and($quick['rooms'][0]['seated'])->toBeTrue()
        ->and(collect($quick['closed'])->pluck('flavor')->all())->toBe([LobbyFlavor::BackPorch]);
});

it('keeps the subtab labels short enough to share one un-scrolling row', function (LobbyShelf $shelf, string $label) {
    /*
     * A PIN, not a preference. Five tabs (All plus these four) share a
     * 358px row at 390px with nothing to scroll, and the fit was measured
     * at ~5px of slack — "House rooms" or "Conference rooms" here and the
     * row scrolls sideways, which reads as the chrome coming apart. The
     * long names still head the shelves themselves.
     */
    expect($shelf->tabLabel())->toBe($label)
        ->and(mb_strlen($shelf->tabLabel()))->toBeLessThanOrEqual(mb_strlen($shelf->heading()));
})->with([
    [LobbyShelf::House, 'House'],
    [LobbyShelf::QuickHits, 'Quick'],
    [LobbyShelf::Spotlight, 'Spotlight'],
    [LobbyShelf::Conference, 'Conference'],
]);

it('sells each flavor from its own shelf', function (LobbyFlavor $flavor, LobbyShelf $shelf) {
    expect($flavor->shelf())->toBe($shelf);
})->with([
    [LobbyFlavor::RankedAction, LobbyShelf::Spotlight],
    [LobbyFlavor::UnderTheLights, LobbyShelf::Spotlight],
    [LobbyFlavor::TwoMinuteDrill, LobbyShelf::QuickHits],
    [LobbyFlavor::UpsetAlley, LobbyShelf::Spotlight],
    [LobbyFlavor::BackPorch, LobbyShelf::QuickHits],
    [LobbyFlavor::SecShowdown, LobbyShelf::Conference],
    [LobbyFlavor::BigTenBlitz, LobbyShelf::Conference],
    [LobbyFlavor::AccAction, LobbyShelf::Conference],
    [LobbyFlavor::Big12Shootout, LobbyShelf::Conference],
    [LobbyFlavor::Pac12AfterDark, LobbyShelf::Conference],
]);
