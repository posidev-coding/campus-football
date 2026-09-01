<?php

use App\Actions\EnterPicks;
use App\Actions\GrantWalletEntry;
use App\Actions\MakePick;
use App\Actions\PublishSlate;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\User;
use App\Models\WalletEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * THE SUPPLY SIDE of the Tallboy economy: rung-ups, milestones and the
 * cooler. Every number here is a constant in GrantWalletEntry rather than a
 * literal in an assertion, because the economy is expected to be wrong on
 * first contact and a rebalance must be a deploy, not a deploy plus a test
 * rewrite. What is pinned is the SHAPE — the tier boundaries, the keys that
 * make a double fire a no-op, and the order the top-off asks its questions in.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

function stocked(int $credits, ?User $user = null): User
{
    $user ??= User::factory()->create();

    if ($credits !== 0) {
        app(GrantWalletEntry::class)->handle($user, 0, $credits, 'test-stock');
    }

    return $user;
}

// ------------------------------------------------------------- the cooler

it('grades the top-off on the balance, at every tier boundary', function (int $balance, int $expected) {
    /*
     * One rule doing two jobs: a floor for the thirsty and a ceiling on
     * hoarding. The boundaries are the whole design, so they are tested AT
     * them rather than in the middle of each band.
     */
    expect(GrantWalletEntry::topOffFor($balance))->toBe($expected);
})->with([
    'empty cooler' => [0, GrantWalletEntry::TOPOFF_EMPTY_CREDITS],
    'still empty at the boundary' => [2, GrantWalletEntry::TOPOFF_EMPTY_CREDITS],
    'room left, first notch' => [3, GrantWalletEntry::TOPOFF_ROOM_CREDITS],
    'room left, last notch' => [5, GrantWalletEntry::TOPOFF_ROOM_CREDITS],
    'stocked' => [6, 0],
    'over-stocked by a rung' => [11, 0],
]);

it('pays the graduated amount into the ledger', function () {
    $user = stocked(4);

    expect(app(GrantWalletEntry::class)->topOff($user))->toBe(GrantWalletEntry::TOPOFF_ROOM_CREDITS)
        ->and($user->fresh()->walletTotals()['credits'])->toBe(4 + GrantWalletEntry::TOPOFF_ROOM_CREDITS);
});

it('tops a wallet up once a football week, however many times Picks is opened', function () {
    $user = stocked(0);
    $wallet = app(GrantWalletEntry::class);

    expect($wallet->topOff($user))->toBe(GrantWalletEntry::TOPOFF_EMPTY_CREDITS)
        // Null is "this week is already claimed", which is not a failure.
        ->and($wallet->topOff($user))->toBeNull()
        ->and($wallet->topOff($user))->toBeNull()
        ->and(WalletEntry::where('reason', GrantWalletEntry::REASON_TOPOFF)->count())->toBe(1);

    // Next Saturday is a new week and a new key.
    $this->travelTo('2026-09-09 12:00:00');

    expect($wallet->topOff($user))->not->toBeNull()
        ->and(WalletEntry::where('reason', GrantWalletEntry::REASON_TOPOFF)->count())->toBe(2);
});

it('spends the week key even when the cooler is full, so it cannot be farmed', function () {
    /*
     * THE FARMING HOLE. A full cooler pays nothing — but if paying nothing
     * meant writing nothing, a reader holding six could spend four and come
     * straight back for a restock, then do it again. The zero row is what
     * makes the key the cap rather than the payment.
     */
    $user = stocked(GrantWalletEntry::COOLER_CAPACITY);
    $wallet = app(GrantWalletEntry::class);

    expect($wallet->topOff($user))->toBe(0)
        ->and(WalletEntry::where('reason', GrantWalletEntry::REASON_TOPOFF)->sole()->credits)->toBe(0);

    // Spend it down inside the same week and come back for more.
    $wallet->handle($user, 0, -4, 'test-spend');

    expect($wallet->topOff($user))->toBeNull()
        ->and($user->fresh()->walletTotals()['credits'])->toBe(2);
});

it('asks the key before it reads the balance', function () {
    /*
     * ORDER, not efficiency. The amount is computed FROM the balance, so a
     * second fire that read first would compute a number and then discover
     * it has nothing to write — a grant whose value depends on when it lost
     * a race. Asked key-first, the second fire computes nothing at all,
     * which is observable as the absence of the balance SUM.
     */
    $user = stocked(3);
    $wallet = app(GrantWalletEntry::class);
    $wallet->topOff($user);

    DB::enableQueryLog();
    $wallet->topOff($user);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1)
        ->and($queries->first())->not->toContain('sum(');
});

it('refuses an unverified account, like every other capped earn', function () {
    $user = User::factory()->unverified()->create();

    expect(app(GrantWalletEntry::class)->topOff($user))->toBeNull()
        ->and(WalletEntry::count())->toBe(0);
});

// ------------------------------------------------------------ the rung-ups

it('back-pays every rung already climbed, then nothing', function () {
    /*
     * Swept rather than fired on promotion, so a reader who reached Rotation
     * before ever opening Picks collects Redshirt AND Rotation on arrival.
     * The keys are what make the sweep safe to run on every visit.
     */
    $user = User::factory()->create();
    app(GrantWalletEntry::class)->handle($user, 800, 0, 'test-xp');

    $expected = GrantWalletEntry::RUNG_CREDITS['Redshirt'] + GrantWalletEntry::RUNG_CREDITS['Rotation'];

    expect(app(GrantWalletEntry::class)->rungUps($user))
        ->toBe(['Redshirt' => GrantWalletEntry::RUNG_CREDITS['Redshirt'], 'Rotation' => GrantWalletEntry::RUNG_CREDITS['Rotation']])
        ->and($user->fresh()->walletTotals()['credits'])->toBe($expected)
        // Second sweep: every key is spent.
        ->and(app(GrantWalletEntry::class)->rungUps($user->fresh()))->toBe([])
        ->and($user->fresh()->walletTotals()['credits'])->toBe($expected);
});

it('pays nothing for standing on the bottom rung', function () {
    // Walk-On is where everybody starts; a start is not an achievement.
    $user = User::factory()->create();
    app(GrantWalletEntry::class)->handle($user, 125, 0, 'test-xp');

    expect(app(GrantWalletEntry::class)->rungUps($user))->toBe([])
        ->and(WalletEntry::where('reason', GrantWalletEntry::REASON_RUNG_UP)->count())->toBe(0);
});

it('refuses rung-ups on an unverified account', function () {
    $user = User::factory()->unverified()->create();
    app(GrantWalletEntry::class)->handle($user, 5000, 0, 'test-xp');

    expect(app(GrantWalletEntry::class)->rungUps($user))->toBe([]);
});

// --------------------------------------------------------- the Picks visit

it('stamps the first visit once and restocks on arrival', function () {
    $user = User::factory()->create();

    $first = app(EnterPicks::class)->handle($user);

    expect($first['first_visit'])->toBeTrue()
        ->and($first['topped_off'])->toBe(GrantWalletEntry::TOPOFF_EMPTY_CREDITS)
        ->and($user->fresh()->hasSeenPicks())->toBeTrue();

    $stamp = $user->fresh()->picks_first_seen_at;

    $this->travelTo('2026-09-03 12:00:00');
    $second = app(EnterPicks::class)->handle($user->fresh());

    expect($second['first_visit'])->toBeFalse()
        // Same football week: the cooler is already claimed.
        ->and($second['topped_off'])->toBeNull()
        ->and($user->fresh()->picks_first_seen_at->toDateTimeString())->toBe($stamp->toDateTimeString());
});

it('opens the Picks screen into a stocked wallet', function () {
    $commissioner = pickemAdmin();

    expect($commissioner->hasSeenPicks())->toBeFalse();

    Livewire::actingAs($commissioner)->test('pickem-home')->assertOk();

    expect($commissioner->fresh()->hasSeenPicks())->toBeTrue()
        ->and($commissioner->fresh()->walletTotals()['credits'])->toBe(GrantWalletEntry::TOPOFF_EMPTY_CREDITS);
});

// ------------------------------------------------------------ the milestones

it('pays the first slate ever entered, once ever', function () {
    [$slate, $alice] = pickemContestants();
    $slateGame = $slate->games()->with('game')->first();

    app(MakePick::class)->handle($alice, $slateGame, $slateGame->game->home_team_id);
    // Changing your mind is the same seat.
    app(MakePick::class)->handle($alice, $slateGame->fresh(), $slateGame->game->away_team_id);

    expect(WalletEntry::where('reason', GrantWalletEntry::REASON_FIRST_SLATE)->count())->toBe(1)
        ->and(WalletEntry::where('reason', GrantWalletEntry::REASON_FIRST_SLATE)->sole()->credits)
        ->toBe(GrantWalletEntry::FIRST_SLATE_CREDITS);
});

it('counts weeks in SATURDAYS, not in seats', function () {
    /*
     * THE MULTI-LEAGUE TRAP. Somebody in five groups seats five slates on
     * one Saturday. Counting entries would hand them the five-week milestone
     * on their very first weekend — the milestone is about coming back.
     */
    $alice = User::factory()->create(['handle' => 'alice']);

    foreach (range(1, 5) as $i) {
        [$commissioner, $group, $contest] = pickemContest();
        GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $alice->id]);

        $slate = pickemDraftSlate($contest);
        app(PublishSlate::class)->handle($commissioner, $slate);

        $slateGame = $slate->fresh()->games()->with('game')->first();
        app(MakePick::class)->handle($alice->fresh(), $slateGame, $slateGame->game->home_team_id);
    }

    $saturdays = Slate::query()
        ->whereIn('id', SlateEntry::where('user_id', $alice->id)->pluck('slate_id'))
        ->distinct()
        ->count('saturday');

    expect(SlateEntry::where('user_id', $alice->id)->count())->toBe(5)
        ->and($saturdays)->toBe(1)
        ->and(WalletEntry::where('reason', GrantWalletEntry::REASON_WEEKS_ENTERED)->count())->toBe(0)
        // The seat itself still paid, once ever.
        ->and(WalletEntry::where('reason', GrantWalletEntry::REASON_FIRST_SLATE)->count())->toBe(1);
});

it('pays the five-week milestone on the Saturday that reaches it', function () {
    $alice = User::factory()->create(['handle' => 'alice']);

    // Four Saturdays already played, on four different weekends.
    foreach (['2026-08-29', '2026-09-12', '2026-09-19', '2026-09-26'] as $saturday) {
        SlateEntry::factory()->create([
            'user_id' => $alice->id,
            'slate_id' => Slate::factory()->create(['saturday' => $saturday])->id,
        ]);
    }

    [$commissioner, $group, $contest] = pickemContest();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $alice->id]);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    $slateGame = $slate->fresh()->games()->with('game')->first();
    app(MakePick::class)->handle($alice->fresh(), $slateGame, $slateGame->game->home_team_id);

    $paid = WalletEntry::where('reason', GrantWalletEntry::REASON_WEEKS_ENTERED)->get();

    expect($paid)->toHaveCount(1)
        ->and($paid->first()->credits)->toBe(GrantWalletEntry::WEEKS_ENTERED_CREDITS[5])
        ->and($paid->first()->key)->toBe('weeks:5');
});

// ----------------------------------------------- the settlement milestones

describe('settlement milestones', function () {
    beforeEach(function () {
        Notification::fake();
    });

    it('pays a perfect week per slate, and a re-settle pays nobody twice', function () {
        [$slate, $alice, $bob] = pickemContestants();

        foreach ($slate->games()->with('game')->orderBy('position')->get() as $index => $slateGame) {
            app(MakePick::class)->handle($alice->fresh(), $slateGame, $slateGame->game->home_team_id);

            // Bob drops exactly one, which is the whole difference.
            $team = $index === 0 ? $slateGame->game->away_team_id : $slateGame->game->home_team_id;
            app(MakePick::class)->handle($bob->fresh(), $slateGame->fresh(), $team);
        }

        $this->travelTo('2026-09-05 20:00:00');

        foreach (range(1, 10) as $position) {
            pickemScore($slate, $position, 28, 7, final: true);
        }

        $this->travelTo('2026-09-06 16:01:00');
        $this->artisan('pickem:settle')->assertSuccessful();

        $perfect = WalletEntry::where('reason', GrantWalletEntry::REASON_PERFECT_WEEK)->get();

        expect($perfect)->toHaveCount(1)
            ->and($perfect->first()->user_id)->toBe($alice->id)
            ->and($perfect->first()->credits)->toBe(GrantWalletEntry::PERFECT_WEEK_CREDITS);

        // Judged on RESULTS: one dropped game is not a clean sheet, whatever
        // the points say.
        expect(WalletEntry::where(['user_id' => $bob->id, 'reason' => GrantWalletEntry::REASON_PERFECT_WEEK])->count())->toBe(0);

        $this->artisan('pickem:settle')->assertSuccessful();
        expect(WalletEntry::where('reason', GrantWalletEntry::REASON_PERFECT_WEEK)->count())->toBe(1);
    });

    it('pays the first room won only for a public room, once ever', function () {
        [, $week] = pickemSeasonWeek();
        [$slate, $alice] = pickemContestants();

        // The same contest, re-homed into a public room: the Lobby is where
        // credits are spent, so taking a stranger's room is the milestone.
        $slate->contest->group->update([
            'kind' => Group::KIND_LOBBY,
            'week_id' => $week->id,
            'member_cap' => 20,
        ]);

        $slateGame = $slate->games()->with('game')->first();
        app(MakePick::class)->handle($alice->fresh(), $slateGame, $slateGame->game->home_team_id);

        $this->travelTo('2026-09-05 20:00:00');

        foreach (range(1, 10) as $position) {
            pickemScore($slate, $position, 28, 7, final: true);
        }

        $this->travelTo('2026-09-06 16:01:00');
        $this->artisan('pickem:settle')->assertSuccessful();

        $won = WalletEntry::where('reason', GrantWalletEntry::REASON_FIRST_ROOM_WIN)->get();

        expect($won)->toHaveCount(1)
            ->and($won->first()->user_id)->toBe($alice->id)
            ->and($won->first()->credits)->toBe(GrantWalletEntry::FIRST_ROOM_WIN_CREDITS)
            ->and($won->first()->key)->toBe(GrantWalletEntry::REASON_FIRST_ROOM_WIN);
    });

    it('pays no room milestone for a private league', function () {
        [$slate, $alice] = pickemContestants();

        $slateGame = $slate->games()->with('game')->first();
        app(MakePick::class)->handle($alice->fresh(), $slateGame, $slateGame->game->home_team_id);

        $this->travelTo('2026-09-05 20:00:00');

        foreach (range(1, 10) as $position) {
            pickemScore($slate, $position, 28, 7, final: true);
        }

        $this->travelTo('2026-09-06 16:01:00');
        $this->artisan('pickem:settle')->assertSuccessful();

        expect(WalletEntry::where('reason', GrantWalletEntry::REASON_FIRST_ROOM_WIN)->count())->toBe(0);
    });
});
