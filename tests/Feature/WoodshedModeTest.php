<?php

use App\Actions\MakePick;
use App\Actions\PublishSlate;
use App\Actions\SettleSlate;
use App\Actions\SpawnPublicContest;
use App\Enums\ContestMode;
use App\Enums\TiebreakerMetric;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\User;
use App\Models\WalletEntry;
use App\Services\Contests\BearPicks;
use App\Services\Contests\WoodshedMode;

/*
 * The founders' game, end to end: tiers of 8/6/4, the Lock at +6/−4 (the
 * only path to negative points), and the Bear — seeded at publish, cloned
 * verbatim between sibling rooms, and strictly beaten for +5 at
 * settlement. A perfect week is 101; a backfired Lock is a real minus.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

/** Fifteen lined Saturday games so a house slate can be suggested. */
function woodshedWeek(): array
{
    [$season, $week] = pickemSeasonWeek();

    foreach (range(1, 15) as $i) {
        $game = pickemGame($season, $week);
        pickemOdd($game);
        $game->predictor()->create(['matchup_quality' => 95 - $i]);
    }

    return [$season, $week];
}

// -------------------------------------------------------------- the engine

it('prices the game: tiers of 8/6/4, the Lock at +6/−4, and the flags only it raises', function () {
    $engine = ContestMode::Woodshed->engine();
    $tierOf = fn (int $tier) => (new SlateGame)->forceFill(['tier' => $tier]);
    $pick = fn (bool $locked) => (new Pick)->forceFill(['locked' => $locked]);

    expect($engine->slateSize())->toBe(15)
        ->and($engine->tierSpec())->toBe([1 => 5, 2 => 5, 3 => 5])
        ->and($engine->pointsFor($tierOf(1)))->toBe(8)
        ->and($engine->pointsFor($tierOf(2)))->toBe(6)
        ->and($engine->pointsFor($tierOf(3)))->toBe(4)
        // Unlocked picks grade like anyone's: the tier on a win, zero on a loss.
        ->and($engine->pointsForPick($tierOf(1), $pick(false), Pick::WIN))->toBe(8)
        ->and($engine->pointsForPick($tierOf(1), $pick(false), Pick::LOSS))->toBe(0)
        // The Lock: +6 on top of the tier, or MINUS four.
        ->and($engine->pointsForPick($tierOf(1), $pick(true), Pick::WIN))->toBe(14)
        ->and($engine->pointsForPick($tierOf(3), $pick(true), Pick::WIN))->toBe(10)
        ->and($engine->pointsForPick($tierOf(1), $pick(true), Pick::LOSS))->toBe(-WoodshedMode::LOCK_PENALTY)
        // Push arm is defense only — the half-point law makes it unreachable.
        ->and($engine->pointsForPick($tierOf(1), $pick(true), Pick::PUSH))->toBe(0)
        ->and($engine->supportsLock())->toBeTrue()
        ->and($engine->hasBear())->toBeTrue()
        // The other modes never grew these mechanics.
        ->and(ContestMode::Classic->engine()->supportsLock())->toBeFalse()
        ->and(ContestMode::Classic->engine()->hasBear())->toBeFalse()
        ->and(ContestMode::Tiered->engine()->supportsLock())->toBeFalse()
        ->and(ContestMode::Tiered->engine()->hasBear())->toBeFalse();
});

it('refuses to publish when the question is not the featured game\'s over/under', function () {
    [, , $contest] = pickemContest(ContestMode::Woodshed);
    $slate = pickemDraftBoard($contest);

    $tiebreakerGame = $slate->tiebreakerGame()->with('game')->first();
    $slate->update([
        'tiebreaker_metric' => TiebreakerMetric::TeamPoints,
        'tiebreaker_team_id' => $tiebreakerGame->game->home_team_id,
    ]);

    expect(ContestMode::Woodshed->engine()->validateForPublish($slate->fresh()))
        ->toContain('picks.publish.featured_metric');
});

// ---------------------------------------------------------------- the Bear

it('boards the Bear at publish: theme by week number, a side on every game', function () {
    [$commissioner, , $contest] = pickemContest(ContestMode::Woodshed);
    $slate = pickemDraftBoard($contest);

    expect(app(PublishSlate::class)->handle($commissioner, $slate))->toBe([]);

    $published = $slate->fresh()->loadMissing('games.game');

    // Week 1 rotates to 'dogs'; the fixtures favor home everywhere, so the
    // Bear rides the away side of all fifteen.
    expect($published->bear_theme)->toBe(BearPicks::THEMES[1])
        ->and($published->games)->toHaveCount(15)
        ->and($published->games->every(
            fn (SlateGame $slateGame) => $slateGame->bear_team_id === $slateGame->game->away_team_id
        ))->toBeTrue();
});

it('fields two Bears in a split opening week — one per card', function () {
    [, , $contest] = pickemContest(ContestMode::Woodshed);
    [, $week] = splitPickemWeek();

    $early = Slate::factory()->create(['contest_id' => $contest->id, 'week_id' => $week->id, 'saturday' => '2026-08-29']);
    $main = Slate::factory()->create(['contest_id' => $contest->id, 'week_id' => $week->id, 'saturday' => '2026-09-05']);

    (new BearPicks)->seed($early);
    (new BearPicks)->seed($main);

    // The theme keys on the FANS' week number, so the 8/29 card is Week 0
    // (favorites) and the main card is Week 1 (dogs) — two Bears, both
    // still predictable.
    expect($early->fresh()->bear_theme)->toBe(BearPicks::THEMES[0])
        ->and($main->fresh()->bear_theme)->toBe(BearPicks::THEMES[1]);
});

it('never boards the Bear on the other modes\' slates', function () {
    [$commissioner, , $contest] = pickemContest(ContestMode::Tiered);
    $slate = pickemDraftBoard($contest);

    expect(app(PublishSlate::class)->handle($commissioner, $slate))->toBe([]);

    $published = $slate->fresh();

    expect($published->bear_theme)->toBeNull()
        ->and($published->games()->whereNotNull('bear_team_id')->count())->toBe(0);
});

it('keeps the sibling\'s Bear verbatim when a room clones — never a reseed', function () {
    [, $week] = woodshedWeek();

    $first = app(SpawnPublicContest::class)->handle(ContestMode::Woodshed, $week);
    expect($first->name)->toBe('The Woodshed Open · Sep 5 · Room 1');

    $firstSlate = Slate::query()
        ->whereHas('contest', fn ($q) => $q->where('group_id', $first->id))
        ->with('games.game')
        ->sole();
    expect($firstSlate->bear_theme)->not->toBeNull();

    // Mutate Room 1's Bear by hand: if Room 2 carries the MUTATION, it
    // cloned; if it carries the week's deterministic seed, it reseeded —
    // and the identical-house-slate rule broke.
    $firstSlate->update(['bear_theme' => 'home']);
    $firstSlate->games->each(fn (SlateGame $slateGame) => $slateGame->update([
        'bear_team_id' => $slateGame->game->home_team_id,
    ]));

    $second = app(SpawnPublicContest::class)->handle(ContestMode::Woodshed, $week);
    $secondSlate = Slate::query()->whereHas('contest', fn ($q) => $q->where('group_id', $second->id))->sole();

    expect($secondSlate->bear_theme)->toBe('home')
        ->and($secondSlate->games()->orderBy('position')->pluck('bear_team_id')->all())
        ->toBe($firstSlate->games()->orderBy('position')->pluck('bear_team_id')->all());
});

// ----------------------------------------------------------- mode identity

it('gives every mode its own mark, colors and rule lines', function () {
    $modes = collect(ContestMode::cases());

    // Distinct marks and palettes — three games, three identities.
    expect($modes->map->icon()->unique())->toHaveCount(3)
        ->and($modes->map(fn (ContestMode $mode) => $mode->palette()['chip'])->unique())->toHaveCount(3);

    foreach ($modes as $mode) {
        expect($mode->palette())->toHaveKeys(['chip', 'icon', 'tile'])
            ->and($mode->ruleLines())->not->toBeEmpty();
    }

    // The founders' numbers, spoken once: the rules seam names the stakes.
    expect(implode(' ', ContestMode::Woodshed->ruleLines()))
        ->toContain('+6')->toContain('−4')->toContain('101');
});

// -------------------------------------------------------------- settlement

it('settles the founders\' way: Lock math in, the Bear strictly beaten pays five more, a backfired Lock goes negative', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Woodshed);
    $slate = pickemDraftBoard($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $slate = $slate->fresh();

    $alice = User::factory()->create(['handle' => 'alice']);
    $bob = User::factory()->create(['handle' => 'bob']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $alice->id]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $bob->id]);

    // The featured game (tier 1): Alice locks the favorite, Bob locks the
    // dog. Fixture state for the wager — the LockPick action has its own
    // suite.
    $featured = $slate->games()->with('game')->orderBy('position')->first();
    app(MakePick::class)->handle($alice, $featured, $featured->game->home_team_id);
    app(MakePick::class)->handle($bob, $featured->fresh(), $featured->game->away_team_id);
    Pick::query()->update(['locked' => true]);

    // Every favorite covers: the Bear (riding week 1's dogs) goes 0-15.
    $this->travelTo('2026-09-05 20:00:00');
    foreach ($slate->games()->with('game')->get() as $slateGame) {
        $slateGame->game->update([
            'home_score' => 28, 'away_score' => 7, 'status' => 'post', 'completed' => true,
        ]);
    }

    $this->travelTo('2026-09-06 16:01:00');
    expect(app(SettleSlate::class)->handle($slate))->toBeTrue();

    $settled = $slate->fresh();

    // Alice: locked tier-1 win = 8 + 6 = 14, strictly beats the Bear's 0
    // for +5 → 19. Bob: locked loss = −4, no bonus, a negative week that
    // PERSISTS — signed columns are the founders' rule wearing DDL.
    $aliceEntry = $settled->entries()->where('user_id', $alice->id)->sole();
    $bobEntry = $settled->entries()->where('user_id', $bob->id)->sole();

    expect(Pick::where('user_id', $alice->id)->sole()->points)->toBe(14)
        ->and(Pick::where('user_id', $bob->id)->sole()->points)->toBe(-4)
        ->and($aliceEntry->final_points)->toBe(19)
        ->and($aliceEntry->beat_bear)->toBeTrue()
        ->and($aliceEntry->won)->toBeTrue()
        ->and($bobEntry->final_points)->toBe(-4)
        ->and($bobEntry->beat_bear)->toBeFalse()
        ->and($bobEntry->won)->toBeFalse();

    // XP rides the FINAL number and floors at zero: 19 × 10 for Alice,
    // nothing for Bob — the wallet is earn-only, a bad Lock never drains it.
    expect(WalletEntry::where(['user_id' => $alice->id, 'reason' => 'pickem-points'])->sole()->xp)->toBe(190)
        ->and(WalletEntry::where(['user_id' => $bob->id, 'reason' => 'pickem-points'])->count())->toBe(0)
        ->and(WalletEntry::where(['user_id' => $alice->id, 'reason' => 'pickem-win'])->sole()->lattes)->toBe(1);

    // Settled is settled: the claim spends once, the keys pay nobody twice.
    expect(app(SettleSlate::class)->handle($settled))->toBeFalse()
        ->and(WalletEntry::where('reason', 'pickem-points')->count())->toBe(1)
        ->and(WalletEntry::where('reason', 'pickem-win')->count())->toBe(1);
});

it('shares the week with the Bear unbeaten: tying him pays nothing extra', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Woodshed);
    $slate = pickemDraftBoard($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $slate = $slate->fresh();

    $carol = User::factory()->create(['handle' => 'carol']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $carol->id]);

    // Carol rides the same dog the Bear rides on a tier-1 game, unlocked.
    $featured = $slate->games()->with('game')->orderBy('position')->first();
    app(MakePick::class)->handle($carol, $featured, $featured->game->away_team_id);

    // The dog covers game one; every other favorite covers. Carol 8, the
    // Bear 8 — identical. Strictly greater means NO bonus on a tie.
    $this->travelTo('2026-09-05 20:00:00');
    foreach ($slate->games()->with('game')->get() as $slateGame) {
        $covered = $slateGame->id === $featured->id
            ? ['home_score' => 10, 'away_score' => 7]   // inside the 6.5
            : ['home_score' => 28, 'away_score' => 7];

        $slateGame->game->update([...$covered, 'status' => 'post', 'completed' => true]);
    }

    $this->travelTo('2026-09-06 16:01:00');
    app(SettleSlate::class)->handle($slate);

    $entry = $slate->fresh()->entries()->where('user_id', $carol->id)->sole();

    expect($entry->final_points)->toBe(8)
        ->and($entry->beat_bear)->toBeFalse();
});
