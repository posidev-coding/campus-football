<?php

use App\Actions\CrushTallboy;
use App\Actions\GrantWalletEntry;
use App\Actions\MakePick;
use App\Actions\PublishSlate;
use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Exceptions\PickLocked;
use App\Exceptions\WalletTooLight;
use App\Models\Contest;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\User;
use App\Models\WalletEntry;
use App\Services\Contests\ClassicMode;
use App\Services\Contests\ModeEngine;
use App\Services\Contests\TieredMode;
use App\Services\Contests\WoodshedMode;
use App\Support\Voice;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * THE SECOND SINK: crush a Tallboy on one game, ±5.
 *
 * Flat and symmetric, and EV-neutral because picks are against the spread —
 * a Tallboy buys VARIANCE, not advantage, which is the whole answer to
 * "won't power users just buy wins?". They cannot; they can only be
 * spectacularly right or spectacularly wrong more often.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
    Notification::fake();
});

/** The engine a contest with these settings would run under. */
function crushEngine(?array $settings, ContestMode $mode = ContestMode::Classic): ModeEngine
{
    return $mode->engine($settings);
}

// ----------------------------------------------------- who may take one

it('prices the wager flat at five, whatever the game is worth', function () {
    /*
     * Five, not ten: most games pay ten, so every score sits on a ten-point
     * lattice and a ±10 wager keeps you ON it, breaking no ties at all. At
     * ±5 a wagering player's score ends in five and everybody else's in
     * zero, so the two can never tie — and half the time the separation is
     * downward, which is why it separates without favoring.
     */
    expect(ModeEngine::TALLBOY_SWING)->toBe(5)
        ->and(5 / (new ClassicMode)->perfectWeek())->toBeLessThanOrEqual(ModeEngine::TALLBOY_LEVERAGE_CEILING);
});

it('refuses every mode that already carries a wager or a kicker', function () {
    // A slate must never offer two wagers, and a second modifier on the
    // same pick is unreadable.
    expect(crushEngine(null, ContestMode::Woodshed)->supportsTallboy())->toBeFalse()
        ->and(crushEngine(['kicker' => 'underdog_ml', 'kicker_points' => 2])->supportsTallboy())->toBeFalse()
        // Upset Alley is the flavored shape of that kicker.
        ->and(crushEngine(LobbyFlavor::UpsetAlley->settings())->supportsTallboy())->toBeFalse();
});

it('holds Two-Minute Drill out on identity, not arithmetic', function () {
    /*
     * At ±5 the leverage here is 10% and comfortably inside the ceiling —
     * the maths does NOT exclude it. Its own blurb sells it as "in and out",
     * and a wager is friction. One public shelf with zero spend decisions
     * is also the clean answer to "is the Lobby pay-to-play?".
     */
    $settings = LobbyFlavor::TwoMinuteDrill->settings();

    expect(5 / crushEngine($settings)->perfectWeek())->toBeLessThan(ModeEngine::TALLBOY_LEVERAGE_CEILING)
        ->and(crushEngine($settings)->supportsTallboy())->toBeFalse()
        // Remove only the identity flag and the arithmetic lets it through.
        ->and(crushEngine(['slate_size' => 5])->supportsTallboy())->toBeTrue();
});

it('asks the CONTEST\'s frozen slate size, not the flavor\'s', function () {
    /*
     * THE TRAP. Ranked Action and all five conference rooms are dynamic —
     * their slate is as big as the Saturday allows, frozen into
     * contests.settings at spawn. A thin conference week can seat three
     * games: a 30-point perfect week, where ±5 is 16.7% and over the
     * ceiling. A static per-flavor allowlist ships a silent over-leverage
     * bug on the first thin Saturday.
     */
    $conference = LobbyFlavor::SecShowdown->settings();

    // A normal Saturday: nine games, 45 points of slate, 11% — allowed.
    expect(crushEngine([...$conference, 'slate_size' => 9])->supportsTallboy())->toBeTrue()
        // Four games, 40 points, 12.5% — still allowed.
        ->and(crushEngine([...$conference, 'slate_size' => 4])->supportsTallboy())->toBeTrue()
        // THREE games, 30 points, 16.7% — refused.
        ->and(crushEngine([...$conference, 'slate_size' => 3])->supportsTallboy())->toBeFalse();
});

it('lets the tiered mode take one, priced off its own perfect week', function () {
    // 9·5 + 7·5 + 4·5 = 100, derived rather than re-typed.
    expect((new TieredMode)->perfectWeek())->toBe(100)
        ->and(crushEngine(null, ContestMode::Tiered)->supportsTallboy())->toBeTrue()
        // The Woodshed's 90 is before the Lock and the Bear.
        ->and((new WoodshedMode)->perfectWeek())->toBe(90);
});

// -------------------------------------------------------------- the price

it('pays plus five right and minus five wrong', function () {
    $engine = new ClassicMode;
    $slateGame = SlateGame::factory()->make(['tier' => null]);

    $staked = Pick::factory()->make(['locked' => true]);
    $plain = Pick::factory()->make(['locked' => false]);

    expect($engine->pointsForPick($slateGame, $staked, Pick::WIN))->toBe(15)
        ->and($engine->pointsForPick($slateGame, $staked, Pick::LOSS))->toBe(-5)
        // A push is unreachable under the half-point law; the arm is defense.
        ->and($engine->pointsForPick($slateGame, $staked, Pick::PUSH))->toBe(0)
        // ...and an unstaked pick is priced like anybody else's.
        ->and($engine->pointsForPick($slateGame, $plain, Pick::WIN))->toBe(10)
        ->and($engine->pointsForPick($slateGame, $plain, Pick::LOSS))->toBe(0);
});

it('leaves a locked pick inert where the mode takes no wager', function () {
    // Two-Minute Drill is excluded, so a locked row there is data, not a
    // wager — it must grade plainly rather than paying a bonus nobody bought.
    $engine = crushEngine(LobbyFlavor::TwoMinuteDrill->settings());
    $slateGame = SlateGame::factory()->make(['tier' => null]);
    $staked = Pick::factory()->make(['locked' => true]);

    expect($engine->pointsForPick($slateGame, $staked, Pick::WIN))->toBe(10)
        ->and($engine->pointsForPick($slateGame, $staked, Pick::LOSS))->toBe(0);
});

it('outweighs the game itself on a tier-3 card, deliberately', function () {
    // Triple Option's tier 3 pays four, so ±5 is bigger than the game. That
    // makes a junk game worth wagering on; scaling to the tier would yield
    // fractions on nines and sevens.
    $engine = new TieredMode;
    $slateGame = SlateGame::factory()->make(['tier' => 3]);
    $staked = Pick::factory()->make(['locked' => true]);

    expect($engine->pointsForPick($slateGame, $staked, Pick::WIN))->toBe(9)
        ->and($engine->pointsForPick($slateGame, $staked, Pick::LOSS))->toBe(-5);
});

// ------------------------------------------------------------- the action

/** A published Shotgun slate with a seated picker. */
function crushSlate(): array
{
    [$slate, $alice] = pickemContestants();

    return [$slate, $alice];
}

/**
 * Set the wallet to EXACTLY this many credits.
 *
 * Called after the picks are made, never before: seating a slate pays the
 * first-slate milestone, so a wallet stocked up front is not the wallet the
 * wager sees. Pinning the balance at the moment under test is the only way
 * these numbers stay readable when the supply side is rebalanced.
 */
function crushBalance(User $user, int $credits): User
{
    $held = (int) WalletEntry::where('user_id', $user->id)->sum('credits');

    if ($held !== $credits) {
        app(GrantWalletEntry::class)->handle($user, 0, $credits - $held, 'test-balance');
    }

    return $user->fresh();
}

it('spends a credit, and refunds it as a new positive row on the pull', function () {
    [$slate, $alice] = crushSlate();
    $slateGame = $slate->games()->with('game')->first();
    app(MakePick::class)->handle($alice, $slateGame, $slateGame->game->home_team_id);
    $alice = crushBalance($alice, 2);

    app(CrushTallboy::class)->handle($alice->fresh(), $slateGame->fresh(), true);

    expect(Pick::sole()->locked)->toBeTrue()
        ->and($alice->fresh()->walletTotals()['credits'])->toBe(1);

    app(CrushTallboy::class)->handle($alice->fresh(), $slateGame->fresh(), false);

    $rows = WalletEntry::where('reason', GrantWalletEntry::REASON_TALLBOY_WAGER)
        ->orderBy('id')->pluck('credits');

    expect(Pick::sole()->locked)->toBeFalse()
        ->and($alice->fresh()->walletTotals()['credits'])->toBe(2)
        // A correction is a NEW ROW, never an edit and never a deletion.
        ->and($rows->all())->toBe([-1, 1]);
});

it('refuses to stake what the wallet cannot cover', function () {
    [$slate, $alice] = crushSlate();
    $slateGame = $slate->games()->with('game')->first();
    app(MakePick::class)->handle($alice, $slateGame, $slateGame->game->home_team_id);
    $alice = crushBalance($alice, 0);

    expect(fn () => app(CrushTallboy::class)->handle($alice->fresh(), $slateGame->fresh(), true))
        ->toThrow(WalletTooLight::class);

    expect(Pick::sole()->locked)->toBeFalse()
        ->and($alice->fresh()->walletTotals()['credits'])->toBe(0);
});

it('moves the wager without charging twice', function () {
    /*
     * ONE WAGER PER SLATE — which is what the leverage ceiling is a
     * guarantee about. The credit bought the WEEK'S wager, not this game's,
     * so moving it is a move and not a second purchase.
     */
    [$slate, $alice] = crushSlate();
    $games = $slate->games()->with('game')->orderBy('position')->take(2)->get();

    foreach ($games as $slateGame) {
        app(MakePick::class)->handle($alice->fresh(), $slateGame, $slateGame->game->home_team_id);
    }

    $alice = crushBalance($alice, 2);

    app(CrushTallboy::class)->handle($alice->fresh(), $games[0]->fresh(), true);
    app(CrushTallboy::class)->handle($alice->fresh(), $games[1]->fresh(), true);

    expect(Pick::where('slate_game_id', $games[0]->id)->sole()->locked)->toBeFalse()
        ->and(Pick::where('slate_game_id', $games[1]->id)->sole()->locked)->toBeTrue()
        ->and(WalletEntry::where('reason', GrantWalletEntry::REASON_TALLBOY_WAGER)->count())->toBe(1)
        ->and($alice->fresh()->walletTotals()['credits'])->toBe(1);
});

it('will not move a wager off a game that has kicked off', function () {
    [$slate, $alice] = crushSlate();
    $games = $slate->games()->with('game')->orderBy('position')->take(2)->get();

    foreach ($games as $slateGame) {
        app(MakePick::class)->handle($alice->fresh(), $slateGame, $slateGame->game->home_team_id);
    }

    $alice = crushBalance($alice, 2);

    app(CrushTallboy::class)->handle($alice->fresh(), $games[0]->fresh(), true);

    // The wager is in play: it neither moves nor pulls.
    $games[0]->game->update(['status' => 'in', 'kickoff_at' => now()->subMinutes(5)]);

    expect(fn () => app(CrushTallboy::class)->handle($alice->fresh(), $games[1]->fresh(), true))
        ->toThrow(PickLocked::class)
        ->and(fn () => app(CrushTallboy::class)->handle($alice->fresh(), $games[0]->fresh(), false))
        ->toThrow(PickLocked::class);

    expect(Pick::where('slate_game_id', $games[0]->id)->sole()->locked)->toBeTrue()
        ->and($alice->fresh()->walletTotals()['credits'])->toBe(1);
});

it('refuses a contest that does not take the wager', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Woodshed);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    $slateGame = $slate->fresh()->games()->with('game')->first();
    app(MakePick::class)->handle($commissioner, $slateGame, $slateGame->game->home_team_id);

    expect(fn () => app(CrushTallboy::class)->handle(
        pickemStocked($commissioner->fresh(), 3), $slateGame->fresh(), true,
    ))->toThrow(InvalidArgumentException::class);
});

// ------------------------------------------------------ through the sheet

it('grades a crushed pick both ways at settlement', function () {
    [$slate, $alice, $bob] = pickemContestants();
    $games = $slate->games()->with('game')->orderBy('position')->get();

    foreach ($games as $index => $slateGame) {
        // Alice crushes game one and gets it WRONG; everything else right.
        $team = $index === 0 ? $slateGame->game->away_team_id : $slateGame->game->home_team_id;
        app(MakePick::class)->handle($alice->fresh(), $slateGame, $team);
        app(MakePick::class)->handle($bob->fresh(), $slateGame->fresh(), $slateGame->game->home_team_id);
    }

    app(CrushTallboy::class)->handle(crushBalance($alice, 2), $games[0]->fresh(), true);

    $this->travelTo('2026-09-05 20:00:00');

    foreach (range(1, 10) as $position) {
        pickemScore($slate, $position, 28, 7, final: true);
    }

    $this->travelTo('2026-09-06 16:01:00');
    $this->artisan('pickem:settle')->assertSuccessful();

    // Nine right at ten, and a backfired wager at minus five: 85. Bob, who
    // wagered nothing, takes the clean hundred.
    expect($slate->entries()->where('user_id', $alice->id)->sole()->final_points)->toBe(85)
        ->and($slate->entries()->where('user_id', $bob->id)->sole()->final_points)->toBe(100)
        // A wagering score ends in five and a plain one in zero: they can
        // never tie, and the separation was DOWNWARD here.
        ->and(85 % 10)->toBe(5);
});

it('pays fifteen on a crushed winner', function () {
    [$slate, $alice] = crushSlate();
    $games = $slate->games()->with('game')->orderBy('position')->get();

    foreach ($games as $slateGame) {
        app(MakePick::class)->handle($alice->fresh(), $slateGame, $slateGame->game->home_team_id);
    }

    app(CrushTallboy::class)->handle(crushBalance($alice, 2), $games[0]->fresh(), true);

    $this->travelTo('2026-09-05 20:00:00');

    foreach (range(1, 10) as $position) {
        pickemScore($slate, $position, 28, 7, final: true);
    }

    $this->travelTo('2026-09-06 16:01:00');
    $this->artisan('pickem:settle')->assertSuccessful();

    expect($slate->entries()->where('user_id', $alice->id)->sole()->final_points)->toBe(105)
        ->and(Pick::where(['user_id' => $alice->id, 'slate_game_id' => $games[0]->id])->sole()->points)->toBe(15);
});

it('offers the control on the sheet, and collapses it once one is staked', function () {
    [$slate, $alice] = crushSlate();
    $games = $slate->games()->with('game')->orderBy('position')->get();

    foreach ($games as $slateGame) {
        app(MakePick::class)->handle($alice->fresh(), $slateGame, $slateGame->game->home_team_id);
    }

    $alice = crushBalance($alice, 2);

    $before = Livewire::actingAs($alice->fresh())
        ->test('group', ['group' => $slate->contest->group])
        ->html();

    // Every card offers it while nothing is staked.
    expect(substr_count($before, 'data-crush-toggle'))->toBe(10)
        ->and($before)->toContain('+5 right · −5 wrong · 1 Tallboy');

    app(CrushTallboy::class)->handle($alice->fresh(), $games[0]->fresh(), true);

    $after = Livewire::actingAs($alice->fresh())
        ->test('group', ['group' => $slate->contest->group])
        ->html();

    // Afterwards it collapses to the card holding it — nine disabled
    // controls is a screen arguing with itself.
    expect(substr_count($after, 'data-crush-toggle'))->toBe(1)
        ->and($after)->toContain('Crushed');
});

it('answers a short wallet in words rather than a dead button', function () {
    [$slate, $alice] = crushSlate();
    $slateGame = $slate->games()->with('game')->first();
    app(MakePick::class)->handle($alice, $slateGame, $slateGame->game->home_team_id);
    $alice = crushBalance($alice, 0);

    Livewire::actingAs($alice->fresh())
        ->test('group', ['group' => $slate->contest->group])
        ->call('crushTallboy', $slateGame->id, true)
        ->assertSee(Voice::line('picks.tallboy.too_light', for: $alice));

    expect(Pick::sole()->locked)->toBeFalse();
});

it('writes the refusal in all three registers', function () {
    foreach (['pg', 'pg13', 'r'] as $rating) {
        expect(Voice::line('picks.tallboy.too_light', for: User::factory()->make(['content_rating' => $rating])))
            ->not->toBe('');
    }
});

it('never offers the wager where the Lock already lives', function () {
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Woodshed);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    $html = Livewire::actingAs($commissioner)->test('group', ['group' => $group])->html();

    // One column, two mechanics, never both on one slate.
    expect($html)->toContain('data-lock-toggle')
        ->and($html)->not->toContain('data-crush-toggle');
});
