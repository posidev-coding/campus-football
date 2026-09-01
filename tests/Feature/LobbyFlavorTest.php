<?php

use App\Actions\JoinGroup;
use App\Actions\SpawnPublicContest;
use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Models\Game;
use App\Models\Group;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\User;
use App\Services\Contests\PickGrader;
use App\Support\GameRanks;
use App\Support\LobbyCatalog;
use App\Support\PickemPreflight;
use App\Support\Voice;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * The flavored lobby: specialty rooms are (mode, flavor) shapes whose
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

it('never cross-clones between a flavor and the standard slate of the same mode', function () {
    [, $week] = lobbyFlavorWeek();

    $standard = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    $flash = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill);

    $standardSlate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $standard->id))->sole();
    $flashSlate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $flash->id))->sole();

    // Same mode, same Saturday — different slates. Without the flavor in
    // the sibling key, the flash room would clone the ten-game slate and
    // fail its own publish validation.
    expect($standardSlate->games()->count())->toBe(10)
        ->and($flashSlate->games()->count())->toBe(5);

    // And a SECOND standard room still clones the standard slate, not the
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
    // to sell, and an honest lobby holds no room rather than a thin one.
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
    // cloned five-game slate with the settings that size it.
    expect($next->name)->toBe('Two-Minute Drill II')
        ->and($next->member_cap)->toBe(10)
        ->and($next->contests()->sole()->settings)->toBe(['slate_size' => 5]);

    $nextSlate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $next->id))->sole();

    expect($nextSlate->games()->count())->toBe(5)
        ->and($nextSlate->saturday->toDateString())->toBe('2026-09-05');
});

it('shelves the lobby in catalog order, with the pitch on the shelf and on the room', function () {
    [, $week] = lobbyFlavorWeek();

    app(SpawnPublicContest::class)->handle(ContestMode::Woodshed, $week);
    app(SpawnPublicContest::class)->handle(ContestMode::Tiered, $week);
    app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);
    app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::TwoMinuteDrill);

    $viewer = pickemAdmin();

    $flash = Group::query()->where('flavor', 'two_minute')->sole();

    Livewire::actingAs($viewer)->test('lobby')
        /*
         * The house shelf leads in MODE order, the specialty shelves
         * follow. Wishbone is the tell: alphabetically it sorts dead
         * last, so an accidental name sort cannot pass this order.
         */
        ->assertSeeInOrder(['Hail Mary', 'Wishbone', 'The Splinter', 'Two-Minute Drill'])
        /*
         * REVERSED 2026-08-31, deliberately. This was an assertDontSee:
         * the pitch belonged to the room screen because thirteen stacked
         * pitches is an essay, not a shelf. Right about PARAGRAPHS and
         * wrong about the shelf — ten flavored rooms shipped with ten
         * personalities and the store rendered none of them, so the names
         * sat over identical rows. The pitch is back on the shelf, capped
         * at ONE truncating line, which is what keeps the rows uniform.
         */
        ->assertSee('The flash card: 5 games, in and out. 10 points a game.');

    // And the room itself still says what it is — the blurb AND its
    // zinger, which is the half a truncating shelf line cannot carry.
    Livewire::actingAs($viewer)->test('group', ['group' => $flash])
        ->assertSee('The flash card: 5 games, in and out. 10 points a game.')
        ->assertSee(Voice::line('lobby.flavor.zinger.two_minute', for: $viewer));
});

it('pays the kicker through the real grading pipeline, not just the engine math', function () {
    [, $week] = lobbyFlavorWeek();

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::UpsetAlley);
    $slate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $room->id))->sole();

    $slateGame = $slate->games()->first();
    $game = Game::query()->findOrFail($slateGame->game_id);

    // The DOG side, read off the frozen favorite — never the spread's sign.
    $dog = $slateGame->favorite_team_id === $game->home_team_id
        ? $game->away_team_id
        : $game->home_team_id;

    $pick = Pick::factory()->create([
        'slate_game_id' => $slateGame->id,
        'user_id' => User::factory()->create()->id,
        'picked_team_id' => $dog,
    ]);

    // Dog wins outright 24-17: covered the number AND won the game.
    $dogIsHome = $dog === $game->home_team_id;
    $game->update([
        'home_score' => $dogIsHome ? 24 : 17,
        'away_score' => $dogIsHome ? 17 : 24,
        'completed' => true,
        'status' => 'post',
    ]);

    app(PickGrader::class)->gradeSlateGame($slateGame->fresh(), $game->fresh());

    /*
     * Twelve, through the SAME grader settlement uses — a grader that
     * drops the contest's settings ($mode->engine() instead of
     * $mode->engine($settings)) pays ten here and cannot stay green.
     */
    expect($pick->fresh()->result)->toBe(Pick::WIN)
        ->and($pick->fresh()->points)->toBe(12);
});

it('respawns a filled Week 0 room on Week 0, never the split week\'s main card', function () {
    $this->travelTo('2026-08-26 12:00:00');

    [, $week] = splitPickemWeek();

    foreach (Game::query()->where('week_id', $week->id)->get() as $game) {
        pickemOdd($game);
    }

    $room = app(SpawnPublicContest::class)->handle(
        ContestMode::Classic, $week, CarbonImmutable::parse('2026-08-29', config('cfb.timezone')),
    );
    $room->update(['member_cap' => 2]);

    app(JoinGroup::class)->handle(User::factory()->create(), $room);
    app(JoinGroup::class)->handle(User::factory()->create(), $room->fresh());

    $next = Group::query()->where('kind', Group::KIND_LOBBY)->whereKeyNot($room->id)->sole();
    $nextSlate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $next->id))->sole();

    // The CARRY, not saturdayOf()'s busiest-card default: a successor that
    // opened on 9/5 mid-Week-0 would strand the rehearsal lobby.
    expect($nextSlate->saturday->toDateString())->toBe('2026-08-29')
        ->and($next->name)->toBe('Flea Flicker');
});

it('clones a dynamic room\'s frozen settings verbatim — never a re-resolve', function () {
    [$season, $week] = pickemSeasonWeek();

    foreach (range(1, 9) as $rank) {
        pickemOdd(pickemGame($season, $week, ['home_rank' => $rank]));
    }

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::RankedAction);
    $room->update(['member_cap' => 2]);

    // The poll turns over mid-week: four of the nine drop out. A respawn
    // that RE-RESOLVED would find five ranked games, miss the minimum, and
    // quietly empty the shelf — the frozen nine must ride the clone.
    Game::query()->where('week_id', $week->id)->where('home_rank', '<=', 4)->update(['home_rank' => null]);
    GameRanks::flush();

    // Ranked Action is a Spotlight room: both joiners ice one down.
    app(JoinGroup::class)->handle(pickemStocked(), $room);
    app(JoinGroup::class)->handle(pickemStocked(), $room->fresh());

    $next = Group::query()
        ->where('kind', Group::KIND_LOBBY)
        ->where('flavor', 'ranked_action')
        ->whereKeyNot($room->id)
        ->sole();

    expect($next->contests()->sole()->settings)
        ->toEqualCanonicalizing(['slate_filter' => 'ranked', 'slate_size' => 9]);

    $nextSlate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $next->id))->sole();

    expect($nextSlate->games()->count())->toBe(9);
});

it('warns when a feasible specialty shelf sits empty', function () {
    [, $week] = lobbyFlavorWeek();

    // Feasible flavors exist and nothing has stocked them: WARN with the
    // sweep named, so the flip checklist reads it and runs the command.
    $flavors = collect(app(PickemPreflight::class)->checks())->keyBy('key')['flavors'];

    expect($flavors['status'])->toBe(PickemPreflight::WARN)
        ->and($flavors['remedy'])->toBe('pickem:open-lobbies');
});

it('says the kicker house rule out loud, over the slate', function () {
    [, $week] = lobbyFlavorWeek();

    $room = app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week, null, LobbyFlavor::UpsetAlley);
    // Upset Alley sits on the Spotlight shelf, and a marquee seat is iced
    // down with a Tallboy — an unfunded viewer is refused at the door.
    $viewer = pickemStocked(pickemAdmin());
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
     * The rehearsal lobby: standard Shotgun downsized to the seven that
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

    /*
     * And the preflight reads this exact state as GREEN with the exempt
     * shelves NAMED — the launch checklist must not train the reader to
     * ignore a red row that only means "it's Week 0".
     */
    $rooms = collect(app(PickemPreflight::class)->checks())->keyBy('key')['rooms'];

    expect($rooms['status'])->toBe(PickemPreflight::OK)
        ->and($rooms['detail'])->toContain('1 of 1 possible')
        ->and($rooms['detail'])->toContain('Triple Option')
        ->and($rooms['detail'])->toContain('not enough games this Saturday');
});

it('pitches a downsized room by the card it deals, on the room and on the invite', function () {
    /*
     * The bug this pins, found in the Week 0 rehearsal: the room screen
     * read "10 games, 10 points each" over a seven-game slate. The slate
     * came from the CONTEST (frozen at spawn by the Saturday that exists)
     * and the pitch came from the MODE, so the two could not agree.
     *
     * Upset Alley is the flavored half of the same bug: its headline
     * number is ten and its Week 0 card is seven.
     */
    $this->travelTo('2026-08-26 12:00:00');

    [, $week] = splitPickemWeek();

    foreach (Game::query()->where('week_id', $week->id)->get() as $game) {
        pickemOdd($game);
    }

    $this->artisan('pickem:open-lobbies')->assertSuccessful();

    $house = Group::query()->where('name', 'Hail Mary')->sole();
    $kicker = Group::query()->where('name', 'Upset Alley')->sole();
    $viewer = pickemAdmin();

    Livewire::actingAs($viewer)->test('group', ['group' => $house])
        ->assertSee('7 games, 10 points each. Every call counts the same.')
        ->assertDontSee('10 games, 10 points each.');

    Livewire::actingAs($viewer)->test('group', ['group' => $kicker])
        ->assertSee('7 games — and +2 on top when your dog covers AND wins outright.');

    // The invite landing sells the same card by the same number — a guest
    // who joins on "10 games" arrives at seven.
    Livewire::actingAs($viewer)->test('join', ['code' => $house->code])
        ->assertSee('7 games, 10 points each. Every call counts the same.')
        ->assertDontSee('10 games, 10 points each.');
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
