<?php

use App\Actions\JoinGroup;
use App\Actions\SpawnPublicContest;
use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Models\Game;
use App\Models\Group;
use App\Models\Slate;
use App\Models\User;

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

    // One room — Shotgun, downsized to the seven that exist, dated to the
    // opening card. Triple Option and the Woodshed sat out, quietly.
    expect($rooms)->toHaveCount(1)
        ->and($rooms->sole()->name)->toBe('Hail Mary')
        ->and($rooms->sole()->contests()->sole()->settings)->toBe(['slate_size' => 7]);

    $slate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $rooms->sole()->id))->sole();

    expect($slate->games()->count())->toBe(7)
        ->and($slate->saturday->toDateString())->toBe('2026-08-29');
});
