<?php

use App\Actions\EnterFilmRoom;
use App\Actions\GrantWalletEntry;
use App\Actions\PostToConversation;
use App\Enums\ContentRating;
use App\Models\Game;
use App\Models\User;
use App\Models\WalletEntry;
use App\Support\RankLadder;
use App\Support\Voice;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * THE GAMIFICATION FINISH: the rank ladder, and the two capped daily earns.
 *
 * The discipline under all of it is that the CAP IS A KEY, not a throttle.
 * The day is stamped into the idempotency key and the `(user_id, key)`
 * unique index does the rest, so these tests mostly prove that a second
 * attempt writes ZERO ROWS rather than that some counter said no.
 */

function gamificationGame(): Game
{
    return Game::factory()->create([
        'home_team_id' => null,
        'away_team_id' => null,
        // Pinned: GameFactory scatters kickoff across four months otherwise.
        'kickoff_at' => '2026-09-05 19:30:00',
    ]);
}

function xpOf(User $user): int
{
    return $user->fresh()->walletTotals()['xp'];
}

it('places every rung of the ladder, at its floor and just under the next', function () {
    expect(RankLadder::name(0))->toBe('Walk-On')
        ->and(RankLadder::name(249))->toBe('Walk-On')
        ->and(RankLadder::name(250))->toBe('Redshirt')
        ->and(RankLadder::name(749))->toBe('Redshirt')
        ->and(RankLadder::name(750))->toBe('Rotation')
        ->and(RankLadder::name(1750))->toBe('Starter')
        ->and(RankLadder::name(3500))->toBe('Captain')
        ->and(RankLadder::name(7000))->toBe('All-American')
        ->and(RankLadder::name(15000))->toBe('Legend')
        ->and(RankLadder::name(999999))->toBe('Legend');
});

it('reports NULL at the top of the ladder rather than a zero standing in for it', function () {
    /*
     * The no-defaults rule, in the smallest place it applies: a Legend has no
     * next rung, and `remaining => 0` would render as a full progress bar
     * under a promotion that is never coming. Null means the caller SKIPS the
     * line — which is what the lobby does.
     */
    $top = RankLadder::for(20000);

    expect($top['next'])->toBeNull()
        ->and($top['at'])->toBeNull()
        ->and($top['remaining'])->toBeNull()
        ->and($top['progress'])->toBe(1.0);

    $climbing = RankLadder::for(1000);

    expect($climbing['name'])->toBe('Rotation')
        ->and($climbing['next'])->toBe('Starter')
        ->and($climbing['remaining'])->toBe(750)
        // A share of the CURRENT rung's span (750→1750), not of the whole
        // ladder — the bar restarts at each promotion.
        ->and(round($climbing['progress'], 3))->toBe(0.25);
});

it('pays for a post, three times a day and no more', function () {
    $user = User::factory()->create();
    $game = gamificationGame();
    $action = app(PostToConversation::class);

    foreach (range(1, 5) as $i) {
        $action->handle($user, $game, "Take number {$i}.");
    }

    // Five posts, three paid: the limiter allows six a minute, the CAP is
    // what stops farming, and they are deliberately different numbers.
    expect(xpOf($user))->toBe(3 * GrantWalletEntry::TALK_XP)
        ->and(WalletEntry::query()->where('reason', GrantWalletEntry::REASON_TALK)->count())->toBe(3);
});

it('stamps the football day into the key, so tomorrow is a fresh allowance', function () {
    $user = User::factory()->create();
    $game = gamificationGame();
    $action = app(PostToConversation::class);

    // 01:00 UTC Sunday is still SATURDAY night in Knoxville — a UTC day
    // boundary would hand out a second allowance in the middle of a game.
    $this->travelTo('2026-09-06 01:00:00');
    $action->handle($user, $game, 'Saturday night take.');

    $this->travelTo('2026-09-06 03:00:00');
    $action->handle($user, $game, 'Still Saturday night.');

    $keys = WalletEntry::query()
        ->where('reason', GrantWalletEntry::REASON_TALK)
        ->pluck('key');

    expect($keys)->each->toStartWith('talk:2026-09-05:');

    // The next Eastern day opens a new set of keys.
    $this->travelTo('2026-09-06 20:00:00');
    $action->handle($user, $game, 'Sunday afternoon take.');

    expect(WalletEntry::query()->where('key', 'like', 'talk:2026-09-06:%')->count())->toBe(1);
});

it('never pays an unverified account', function () {
    // Earning is gated on verification everywhere except the seeded
    // first-team grant, which does not come through daily().
    $user = User::factory()->unverified()->create();

    $paid = app(GrantWalletEntry::class)->daily(
        $user, 5, 0, GrantWalletEntry::REASON_TALK, GrantWalletEntry::TALK_DAILY_CAP,
    );

    expect($paid)->toBeFalse()
        ->and(WalletEntry::query()->count())->toBe(0);
});

it('pays the Film Room once per GAME, however many times it is opened', function () {
    $user = User::factory()->create();
    $game = gamificationGame();
    $action = app(EnterFilmRoom::class);

    expect($action->handle($user, $game, 'box'))->toBeTrue()
        // Re-reading the same box score is not a second earn.
        ->and($action->handle($user, $game, 'box'))->toBeFalse()
        // Nor is reading its preview: the slot is the GAME.
        ->and($action->handle($user, $game, 'preview'))->toBeFalse();

    expect(xpOf($user))->toBe(GrantWalletEntry::FILM_ROOM_XP);
});

it('caps the Film Room at a handful of different games a day', function () {
    $user = User::factory()->create();
    $action = app(EnterFilmRoom::class);

    $games = collect(range(1, GrantWalletEntry::FILM_ROOM_DAILY_CAP + 3))
        ->map(fn () => gamificationGame());

    $paid = $games->filter(fn (Game $game) => $action->handle($user, $game, 'box'))->count();

    expect($paid)->toBe(GrantWalletEntry::FILM_ROOM_DAILY_CAP)
        ->and(xpOf($user))->toBe(GrantWalletEntry::FILM_ROOM_DAILY_CAP * GrantWalletEntry::FILM_ROOM_XP);
});

it('pays only for the tabs that are actually film', function () {
    // A score is not film. Live, Scoring, Drives and Odds are scanning
    // surfaces and earn nothing — the reward is meant to find the reader who
    // opened a box score, not to pay for landing on the page.
    $user = User::factory()->create();
    $game = gamificationGame();
    $action = app(EnterFilmRoom::class);

    foreach (['live', 'recap', 'scoring', 'drives', 'odds'] as $tab) {
        expect($action->handle($user, $game, $tab))->toBeFalse();
    }

    expect(WalletEntry::query()->count())->toBe(0);
});

it('opens the Film Room from the game screen, on mount and on a tab change', function () {
    $user = User::factory()->create();
    $game = Game::factory()->finished(24, 17)->create([
        'home_team_id' => null, 'away_team_id' => null,
        'kickoff_at' => '2026-09-05 19:30:00',
    ]);

    $component = Livewire::actingAs($user)->test('game', ['game' => $game]);

    // A finished game leads on Recap, which is not film — nothing yet.
    expect(WalletEntry::query()->where('reason', GrantWalletEntry::REASON_FILM_ROOM)->count())->toBe(0);

    $component->set('tab', 'box');

    expect(WalletEntry::query()->where('reason', GrantWalletEntry::REASON_FILM_ROOM)->count())->toBe(1);
});

it('does not pay again every time a live game polls', function () {
    /*
     * The earn hangs off mount() and the tab hook, never render() — a live
     * game re-renders every thirty seconds, and a Film Room wired into the
     * render path would be a query per poll forever. The key would stop it
     * paying twice; nothing would stop it ASKING twice.
     */
    $user = User::factory()->create();
    $game = Game::factory()->create([
        'home_team_id' => null, 'away_team_id' => null,
        'status' => 'in', 'completed' => false,
        'kickoff_at' => now()->subHour(),
    ]);

    $component = Livewire::actingAs($user)->test('game', ['game' => $game])->set('tab', 'box');

    $before = WalletEntry::query()->where('reason', GrantWalletEntry::REASON_FILM_ROOM)->count();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $component->call('poll');

    expect(WalletEntry::query()->where('reason', GrantWalletEntry::REASON_FILM_ROOM)->count())->toBe($before);
    expect($queries)->toBeGreaterThan(0);
});

it('earns nothing for a guest reading a box score', function () {
    // Reading stays free and ungated — the Film Room never becomes a reason
    // to make somebody sign in.
    $game = gamificationGame();

    Livewire::test('game', ['game' => $game])->set('tab', 'box');

    expect(WalletEntry::query()->count())->toBe(0);
});

it('wears the earned rank in the wallet chips, not a hardcoded starting rung', function () {
    $user = User::factory()->create();

    app(GrantWalletEntry::class)->handle($user, 4000, 0, 'test-seed', 'test-seed');

    $this->actingAs($user->fresh())
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Captain')
        ->assertDontSee('Rookie');
});

it('names the next rung on the lobby, and skips the line at the top', function () {
    $user = User::factory()->create(['admin' => true, 'content_rating' => ContentRating::Pg13]);

    app(GrantWalletEntry::class)->handle($user, 1000, 0, 'test-seed', 'test-seed');

    Livewire::actingAs($user->fresh())->test('lobby')
        ->assertSee('Rotation')
        ->assertSee(Voice::line('rank.to_next', ['remaining' => '750', 'next' => 'Starter'], for: $user));

    $top = User::factory()->create(['admin' => true, 'content_rating' => ContentRating::Pg13]);
    app(GrantWalletEntry::class)->handle($top, 20000, 0, 'test-seed', 'test-seed');

    Livewire::actingAs($top->fresh())->test('lobby')
        ->assertSee('Legend')
        ->assertSee(Voice::line('rank.topped_out', for: $top));
});

it('speaks every register on the rank family', function () {
    $lines = (new ReflectionClass(Voice::class))->getConstant('LINES');

    $keys = array_keys(array_filter(
        $lines,
        fn (string $key) => str_starts_with($key, 'rank.'),
        ARRAY_FILTER_USE_KEY,
    ));

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        foreach (['pg', 'pg13', 'r'] as $register) {
            expect($lines[$key])->toHaveKey($register);
        }
    }

    /*
     * The rung NAMES deliberately do not live in Voice: a rank is a label a
     * reader scans for and compares with somebody else's, so it says the same
     * word in every register, and RankLadder::RUNGS is its only source.
     *
     * Scoped to the `rank.` family rather than swept across every line —
     * "Captains go down with the ship" is the commissioner's copy and has
     * nothing to do with the ladder. What must never happen is a rung name
     * BAKED INTO the ladder's own copy, where it would drift the moment the
     * thresholds are rebalanced.
     */
    foreach ($keys as $key) {
        foreach ($lines[$key] as $line) {
            foreach (array_keys(RankLadder::RUNGS) as $rung) {
                expect($line)->not->toContain($rung);
            }
        }
    }
});
