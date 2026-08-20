<?php

use App\Actions\JoinGroup;
use App\Actions\SpawnPublicContest;
use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Models\Game;
use App\Models\Group;
use App\Models\Slate;
use App\Models\User;
use App\Support\LobbyCatalog;
use App\Support\PickemPreflight;
use App\Support\Voice;
use Livewire\Livewire;

/*
 * The flavored floor: specialty rooms are (mode, flavor) shapes whose
 * rules live entirely in contests.settings, stamped at spawn. The facts
 * this file exists to hold: a flavor's settings freeze at spawn, siblings
 * clone only WITHIN a flavor, an infeasible Saturday spawns nothing, and
 * a filled room's successor keeps the whole identity.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

/** Sixteen lined, scored Saturday games — a healthy main-card week. */
function lobbyFlavorWeek(): array
{
    [$season, $week] = pickemSeasonWeek();

    foreach (range(1, 16) as $i) {
        $game = pickemGame($season, $week);
        pickemOdd($game);
        $game->predictor()->create(['matchup_quality' => 95 - $i]);
    }

    return [$season, $week];
}

it('spawns a flavored room: marquee name, its own cap, settings stamped on the contest', function () {
    [, $week] = lobbyFlavorWeek();

    $room = app(SpawnPublicContest::class)->handle(
        ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill,
    );

    expect($room)->not->toBeNull()
        ->and($room->name)->toBe('Two-Minute Drill')
        ->and($room->flavor)->toBe('two_minute')
        ->and($room->member_cap)->toBe(10);

    $slate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $room->id))->sole();

    expect($slate->status)->toBe(Slate::PUBLISHED)
        // The flash card: five games, from the settings the spawn stamped.
        ->and($slate->games()->count())->toBe(5)
        ->and($room->contests()->sole()->settings)->toBe(['slate_size' => 5]);
});

it('never cross-clones between a flavor and the standard board of the same mode', function () {
    [, $week] = lobbyFlavorWeek();

    $standard = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    $flash = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill);

    $standardSlate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $standard->id))->sole();
    $flashSlate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $flash->id))->sole();

    // Same mode, same Saturday — different boards. Without the flavor in
    // the sibling key, the flash room would clone the ten-game board and
    // fail its own publish validation.
    expect($standardSlate->games()->count())->toBe(10)
        ->and($flashSlate->games()->count())->toBe(5);

    // And a SECOND standard room still clones the standard board, not the
    // flash one — ten games, identical lines.
    $second = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    $secondSlate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $second->id))->sole();

    expect($second->name)->toBe('Flea Flicker')
        ->and($secondSlate->games()->orderBy('position')->pluck('spread', 'game_id')->all())
        ->toBe($standardSlate->games()->orderBy('position')->pluck('spread', 'game_id')->all());
});

it('freezes a dynamic room at the Saturday\'s whole admitted count', function () {
    [$season, $week] = pickemSeasonWeek();

    // Nine ranked games and seven unranked ones, all lined.
    foreach (range(1, 9) as $rank) {
        pickemOdd(pickemGame($season, $week, ['home_rank' => $rank]));
    }
    foreach (range(1, 7) as $i) {
        pickemOdd(pickemGame($season, $week));
    }

    $room = app(SpawnPublicContest::class)->handle(
        ContestMode::Classic, $week, null, LobbyFlavor::RankedAction,
    );

    expect($room->name)->toBe('Ranked Action')
        ->and($room->member_cap)->toBe(50)
        ->and($room->contests()->sole()->settings)
        ->toEqualCanonicalizing(['slate_filter' => 'ranked', 'slate_size' => 9]);

    $slate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $room->id))->sole();

    expect($slate->games()->count())->toBe(9);
});

it('spawns nothing when the Saturday cannot support the flavor', function () {
    // Sixteen lined games, none at night — Under the Lights has no card
    // to sell, and an honest floor holds no room rather than a thin one.
    [, $week] = lobbyFlavorWeek();

    $room = app(SpawnPublicContest::class)->handle(
        ContestMode::Classic, $week, null, LobbyFlavor::UnderTheLights,
    );

    expect($room)->toBeNull()
        ->and(Group::query()->where('flavor', 'under_lights')->exists())->toBeFalse();
});

it('respawns a filled room as the SAME shape: flavor, cap, settings, Saturday', function () {
    [, $week] = lobbyFlavorWeek();

    $room = app(SpawnPublicContest::class)->handle(
        ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill,
    );
    $room->update(['member_cap' => 2]);

    app(JoinGroup::class)->handle(User::factory()->create(), $room);
    app(JoinGroup::class)->handle(User::factory()->create(), $room->fresh());

    $next = Group::query()
        ->where('kind', Group::KIND_LOBBY)
        ->where('flavor', 'two_minute')
        ->whereKeyNot($room->id)
        ->sole();

    // The whole identity carries: the marquee's numeral successor, the
    // flavor's own cap (never the filled room's dev-tweaked one), and the
    // cloned five-game board with the settings that size it.
    expect($next->name)->toBe('Two-Minute Drill II')
        ->and($next->member_cap)->toBe(10)
        ->and($next->contests()->sole()->settings)->toBe(['slate_size' => 5]);

    $nextSlate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $next->id))->sole();

    expect($nextSlate->games()->count())->toBe(5)
        ->and($nextSlate->saturday->toDateString())->toBe('2026-09-05');
});

it('sells the floor in catalog order with honest flavored cards', function () {
    [, $week] = lobbyFlavorWeek();

    app(SpawnPublicContest::class)->handle(ContestMode::Woodshed, $week);
    app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill);

    $viewer = pickemAdmin();

    Livewire::actingAs($viewer)->test('lobby')
        // Standard rooms lead in mode order; the specialty shelf follows —
        // never alphabetical, which would bury Hail Mary under Back Porch.
        ->assertSeeInOrder(['Hail Mary', 'The Splinter', 'Two-Minute Drill'])
        // The flavored card sells ITS card, not the mode's ten-game pitch.
        ->assertSee('The flash card: 5 games, in and out. 10 points a game.')
        ->assertSee(Voice::line('lobby.flavor.zinger.two_minute', for: $viewer));
});

it('says the kicker house rule out loud, over the board', function () {
    [, $week] = lobbyFlavorWeek();

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::UpsetAlley);
    $viewer = pickemAdmin();
    app(JoinGroup::class)->handle($viewer, $room);

    Livewire::actingAs($viewer)->test('group', ['group' => $room->fresh()])
        ->assertSee(Voice::line('picks.kicker.underdog_note', ['points' => 2], for: $viewer));
});

it('fronts the conference family with the viewer\'s own conference', function () {
    $mine = new Group(['kind' => Group::KIND_LOBBY, 'week_id' => 1, 'flavor' => 'conf_b1g', 'name' => 'Big Ten Blitz']);
    $sec = new Group(['kind' => Group::KIND_LOBBY, 'week_id' => 1, 'flavor' => 'conf_sec', 'name' => 'SEC Showdown']);

    // My conference leads the family; without a viewer it sits in case
    // order behind the SEC.
    expect(LobbyCatalog::sortKey($mine, 'big10') <=> LobbyCatalog::sortKey($sec, 'big10'))->toBeLessThan(0)
        ->and(LobbyCatalog::sortKey($mine) <=> LobbyCatalog::sortKey($sec))->toBeGreaterThan(0);
});

it('stocks only what the opening card can seat, through the sweep', function () {
    // Week 0's reality: the split week's first Saturday holds seven lined
    // games. The fifteen-game modes cannot publish; standard Shotgun sells
    // the card that exists.
    $this->travelTo('2026-08-26 12:00:00');

    [$season, $week] = splitPickemWeek();

    foreach (Game::query()->where('week_id', $week->id)->get() as $game) {
        pickemOdd($game);
    }

    $this->artisan('pickem:open-lobbies')->assertSuccessful();

    $rooms = Group::query()->where('kind', Group::KIND_LOBBY)->where('week_id', $week->id)->get();

    /*
     * The rehearsal floor: standard Shotgun downsized to the seven that
     * exist, the flash card, and the kicker room at seven. The fifteen-
     * game modes, the themed rooms and the conference family all sat out,
     * quietly — feasibility, not failure.
     */
    expect($rooms->pluck('name')->all())->toEqualCanonicalizing(['Hail Mary', 'Two-Minute Drill', 'Upset Alley']);

    $standard = $rooms->firstWhere('name', 'Hail Mary');

    expect($standard->contests()->sole()->settings)->toBe(['slate_size' => 7]);

    $slate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $standard->id))->sole();

    expect($slate->games()->count())->toBe(7)
        ->and($slate->saturday->toDateString())->toBe('2026-08-29');
});

it('stocks the specialty shelf and reports it honestly in the preflight', function () {
    [, $week] = lobbyFlavorWeek();

    $this->artisan('pickem:open-lobbies')->assertSuccessful();

    $flavors = collect(app(PickemPreflight::class)->checks())->keyBy('key')['flavors'];

    // Everything possible is stocked (OK, not WARN), and the skipped
    // shelves are NAMED so an empty slot reads as designed, not broken.
    expect($flavors['status'])->toBe(PickemPreflight::OK)
        ->and($flavors['detail'])->toContain('3 of 3 possible')
        ->and($flavors['detail'])->toContain('Skipped:')
        ->and($flavors['detail'])->toContain('Ranked Action')
        ->and($flavors['detail'])->toContain('Pac-12 After Dark');
});
