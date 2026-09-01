<?php

use App\Actions\GrantWalletEntry;
use App\Actions\JoinGroup;
use App\Actions\SpawnPublicContest;
use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Enums\LobbyShelf;
use App\Exceptions\WalletTooLight;
use App\Models\Group;
use App\Models\User;
use App\Models\WalletEntry;
use App\Support\Voice;
use Livewire\Livewire;

/*
 * THE FIRST SINK: a marquee seat costs a Tallboy.
 *
 * What matters here is the SHAPE of the wall, not the number. The Lobby is
 * the front door for anybody without a private group, so the price is
 * confined to one shelf and every other shelf has to stay free — a
 * newcomer holding nothing may never be locked out of the store.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

/** A stocked Saturday of ranked games — what a Spotlight room needs. */
function entryWeek(): array
{
    [$season, $week] = pickemSeasonWeek();

    foreach (range(1, 12) as $rank) {
        pickemOdd(pickemGame($season, $week, ['home_rank' => $rank]));
    }

    return [$season, $week];
}

// ------------------------------------------------------------- the price

it('charges the Spotlight shelf and nothing else', function () {
    /*
     * Read off data that already exists: the flavor names a shelf and the
     * shelf owns the price. A static list of paying flavors would have to
     * be remembered every time one is added.
     */
    expect(LobbyShelf::Spotlight->entryCredits())->toBe(1)
        ->and(LobbyShelf::House->entryCredits())->toBe(0)
        ->and(LobbyShelf::QuickHits->entryCredits())->toBe(0)
        ->and(LobbyShelf::Conference->entryCredits())->toBe(0);
});

it('keeps the whole Quick Hits shelf credit-free', function () {
    // No entry cost and (in the next PR) no wager either. That fell out of
    // the rules rather than being designed, and it is the clean answer to
    // "is the Lobby pay-to-play?".
    foreach ([LobbyFlavor::TwoMinuteDrill, LobbyFlavor::BackPorch] as $flavor) {
        expect($flavor->shelf())->toBe(LobbyShelf::QuickHits)
            ->and($flavor->shelf()->entryCredits())->toBe(0);
    }
});

it('never charges for a seat in a private league', function () {
    /*
     * Decision 3, and the thing that dissolves the multi-group problem: a
     * member of four leagues would otherwise need four times the income,
     * and any "everybody can play" rule would have to know how many groups
     * you are in. You never spend inside a group, so it never comes up.
     */
    $group = Group::factory()->create();

    expect($group->entryCredits())->toBe(0);

    app(JoinGroup::class)->handle($user = User::factory()->create(), $group);

    expect(WalletEntry::where('user_id', $user->id)->count())->toBe(1)
        ->and(WalletEntry::where('reason', GrantWalletEntry::REASON_ROOM_ENTRY)->count())->toBe(0);
});

it('spends a Tallboy on a marquee seat, as a negative keyless row', function () {
    [, $week] = entryWeek();
    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::RankedAction);
    $user = pickemStocked(credits: 3);

    app(JoinGroup::class)->handle($user, $room);

    $spend = WalletEntry::where('reason', GrantWalletEntry::REASON_ROOM_ENTRY)->sole();

    expect($spend->credits)->toBe(-1)
        // Keyless: a contest entry spends every entry, and a key would make
        // the second room of the season free.
        ->and($spend->key)->toBeNull()
        ->and($user->fresh()->walletTotals()['credits'])->toBe(2);
});

it('refuses the seat rather than overdrawing the wallet', function () {
    [, $week] = entryWeek();
    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::RankedAction);
    $user = User::factory()->create();

    expect(fn () => app(JoinGroup::class)->handle($user, $room))->toThrow(WalletTooLight::class);

    // No seat, no row, and above all no negative balance — the ledger has
    // deliberately no balance column to correct afterwards.
    expect($user->fresh()->walletTotals()['credits'])->toBe(0)
        ->and($room->memberships()->count())->toBe(0)
        ->and(WalletEntry::where('reason', GrantWalletEntry::REASON_ROOM_ENTRY)->count())->toBe(0);
});

it('lets a free shelf seat a wallet holding nothing', function () {
    // The whole point of confining the price to one shelf.
    [, $week] = entryWeek();
    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill);

    app(JoinGroup::class)->handle($user = User::factory()->create(), $room);

    expect($room->memberships()->count())->toBe(1)
        ->and($user->fresh()->walletTotals()['credits'])->toBe(0);
});

it('does not charge twice for a seat already held', function () {
    [, $week] = entryWeek();
    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::RankedAction);
    $user = pickemStocked(credits: 2);

    app(JoinGroup::class)->handle($user, $room);
    // The button you already pressed must never scold you — or bill you.
    app(JoinGroup::class)->handle($user->fresh(), $room->fresh());

    expect(WalletEntry::where('reason', GrantWalletEntry::REASON_ROOM_ENTRY)->count())->toBe(1)
        ->and($user->fresh()->walletTotals()['credits'])->toBe(1);
});

// -------------------------------------------------------------- the copy

it('says the price on the card, and the shelf says it in every register', function () {
    [, $week] = entryWeek();
    app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::RankedAction);
    app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill);

    $viewer = pickemAdmin();

    Livewire::actingAs($viewer)->test('lobby')
        // The price is a FACT and the same words in every register...
        ->assertSee('1 Tallboy to enter')
        // ...the button carries the one canonical verb...
        ->assertSee('Ice down')
        // ...and the free shelf keeps the plain door.
        ->assertSee('Join')
        // The shelf's own line is where the slang lives.
        ->assertSee(Voice::line('lobby.shelf.spotlight', for: $viewer));
});

it('answers a short wallet in words rather than an exception', function () {
    [, $week] = entryWeek();
    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::RankedAction);

    $viewer = pickemAdmin();

    Livewire::actingAs($viewer)->test('lobby')
        ->call('joinLobby', $room->id)
        ->assertHasErrors('lobbies')
        ->assertSee(Voice::line('contest.room.too_light', for: $viewer));

    expect($room->memberships()->count())->toBe(0);
});

it('writes the refusal in all three registers', function () {
    // A price the reader cannot pay is the one place the Lobby could read
    // as closed, so every register has to point at the free shelves.
    foreach (['pg', 'pg13', 'r'] as $rating) {
        $user = User::factory()->make(['content_rating' => $rating]);

        expect(Voice::line('contest.room.too_light', for: $user))->not->toBe('');
    }
});
