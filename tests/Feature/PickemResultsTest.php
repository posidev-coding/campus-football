<?php

use App\Actions\SettleSlate;
use App\Enums\ContestMode;
use App\Jobs\AnnounceSlateResults;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Models\User;
use App\Models\WalletEntry;
use App\Notifications\SlateMissed;
use App\Notifications\SlateSettled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

/*
 * "THE WEEK IS OFFICIAL" — the results announcement.
 *
 * The fact this file exists to hold: `settled_at` claims the MONEY and
 * `results_announced_at` claims the NOISE, and they are separate columns so
 * a botched announcement can be repaired without the wallet hearing about
 * it. A queue retry that re-runs the fan-out must mail nobody twice.
 */

beforeEach(function () {
    Notification::fake();
    $this->travelTo('2026-09-13 13:00:00');
});

/**
 * A settled-ready slate of three games the home side covers, with each
 * player picking `$correct` of them right.
 *
 * The points are EARNED, not stamped: SettleSlate regrades from real picks
 * and overwrites final_points, so a fixture that writes totals directly has
 * them silently replaced by zeros and every player ties.
 *
 * @param  list<int>  $correct  correct picks per player, best first
 * @return array{0: Slate, 1: Collection<int, User>}
 */
function resultsSlate(array $correct = [3, 2, 1], bool $exhibition = false): array
{
    [$season, $week] = pickemSeasonWeek();
    [, $group, $contest] = pickemContest(ContestMode::Classic);

    $slate = Slate::factory()->create([
        'contest_id' => $contest->id,
        'week_id' => $week->id,
        'saturday' => '2026-09-12',
        'status' => Slate::PRELIM,
        'published_at' => '2026-09-08 12:00:00',
        'exhibition' => $exhibition,
    ]);

    foreach (range(1, 3) as $position) {
        // Home wins by ten against a 6.5 handicap: picking home covers.
        $game = pickemGame($season, $week, [
            'kickoff_at' => '2026-09-12 16:00:00',
            'completed' => true,
            'home_score' => 30,
            'away_score' => 20,
        ]);
        pickemOdd($game);

        SlateGame::factory()->create([
            'slate_id' => $slate->id,
            'game_id' => $game->id,
            'position' => $position,
            'spread' => -6.5,
            'market_spread' => -6.5,
            'favorite_team_id' => $game->home_team_id,
            'odds_provider' => 'ESPN BET',
            'odds_captured_at' => '2026-09-09 09:00:00',
        ]);
    }

    $slate = $slate->fresh()->load('games.game');

    $players = collect($correct)->map(function (int $hits) use ($group, $slate) {
        $user = pickemAdmin();

        GroupMember::factory()->create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'created_at' => '2026-09-01 12:00:00',
        ]);

        SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $user->id]);

        foreach ($slate->games as $index => $slateGame) {
            $game = $slateGame->game;

            Pick::factory()->create([
                'slate_game_id' => $slateGame->id,
                'user_id' => $user->id,
                'picked_team_id' => $index < $hits ? $game->home_team_id : $game->away_team_id,
            ]);
        }

        return $user;
    });

    return [$slate->fresh()->load(['games.game', 'entries.user', 'contest.group', 'week']), $players];
}

/** Settle through the real action, which is what dispatches the announcement. */
function settleResults(Slate $slate): bool
{
    return app(SettleSlate::class)->handle($slate);
}

it('announces once, however many times the sweep runs', function () {
    [$slate, $players] = resultsSlate();

    expect(settleResults($slate))->toBeTrue();
    expect($slate->fresh()->results_announced_at)->not->toBeNull();

    // The sweep is hourly and settlement is already idempotent; the second
    // pass must reach neither the wallet nor the inbox.
    expect(settleResults($slate->fresh()))->toBeFalse();

    Notification::assertSentToTimes($players->first(), SlateSettled::class, 1);
});

it('re-running the announcement job alone still mails nobody twice', function () {
    /*
     * The retry case, and the reason the claim is taken BEFORE the batch is
     * built: a timeout or a worker restart re-runs this whole job, and
     * without its own claim every entrant is mailed a second time.
     */
    [$slate, $players] = resultsSlate();

    settleResults($slate);
    Notification::assertSentToTimes($players->first(), SlateSettled::class, 1);

    (new AnnounceSlateResults($slate->id))->handle();

    Notification::assertSentToTimes($players->first(), SlateSettled::class, 1);
});

it('keeps the noise repairable without touching the money', function () {
    [$slate, $players] = resultsSlate();

    settleResults($slate);

    $paid = WalletEntry::query()->where('reason', 'pickem-win')->count();

    // The repair: null the announcement claim and re-run. Payouts are keyed
    // and settled_at still stands, so nothing is paid twice.
    $slate->fresh()->forceFill(['results_announced_at' => null])->save();
    (new AnnounceSlateResults($slate->id))->handle();

    expect(WalletEntry::query()->where('reason', 'pickem-win')->count())->toBe($paid)
        ->and($slate->fresh()->settled_at)->not->toBeNull();

    Notification::assertSentToTimes($players->first(), SlateSettled::class, 2);
});

it('tells the winner they won, and the rest where they finished', function () {
    [$slate, $players] = resultsSlate([3, 2, 1]);
    [$winner, $second, $third] = $players->all();

    settleResults($slate);

    Notification::assertSentTo($winner, SlateSettled::class,
        fn (SlateSettled $notification) => $notification->result['won'] === true
            && $notification->result['points'] === 30);

    Notification::assertSentTo($third, SlateSettled::class,
        fn (SlateSettled $notification) => $notification->result['won'] === false
            && $notification->result['place'] === '3rd'
            && $notification->result['field'] === 3);

    unset($second);
});

it('names the nemesis: one place up, or one place down when you won', function () {
    /*
     * The rival moment, folded in as a line rather than a fourth send. A
     * weekly pick'em rivalry IS week to week, and this is the adjacency the
     * settled field already knows.
     */
    [$slate, $players] = resultsSlate([3, 2, 1]);
    [$winner, $second] = $players->all();

    settleResults($slate);

    // Second place looks UP at the winner, by three.
    Notification::assertSentTo($second, SlateSettled::class,
        fn (SlateSettled $n) => $n->result['rival'] === '@'.$winner->handle
            && $n->result['margin'] === '10');

    // The winner looks DOWN at whoever was closest.
    Notification::assertSentTo($winner, SlateSettled::class,
        fn (SlateSettled $n) => $n->result['rival'] === '@'.$second->handle
            && $n->result['margin'] === '10');
});

it('says practice out loud on an exhibition week', function () {
    /*
     * The Aug 29 rehearsal. Without this the app emails people "You won
     * Week 0" about a week Slate::counts() says the season does not
     * remember.
     */
    [$slate, $players] = resultsSlate([3, 1], exhibition: true);

    settleResults($slate);

    Notification::assertSentTo($players->first(), SlateSettled::class,
        fn (SlateSettled $n) => $n->result['exhibition'] === true);
});

it('pushes a missed week without mailing about a contest they did not enter', function () {
    [$slate, $players] = resultsSlate([3, 1]);
    $group = $slate->contest->group;

    // A member who never picked: no entry row, joined before the card went up.
    $absent = pickemAdmin();
    GroupMember::factory()->create([
        'group_id' => $group->id,
        'user_id' => $absent->id,
        'created_at' => '2026-09-01 12:00:00',
    ]);

    settleResults($slate);

    Notification::assertSentTo($absent, SlateMissed::class,
        fn (SlateMissed $n) => $n->result['winner'] === '@'.$players->first()->handle);

    // Mail is the line that must not be crossed: an email about a contest
    // somebody did not enter is unsolicited mail about a thing they did not do.
    expect((new SlateMissed(['week' => 'W', 'group' => 'G', 'winner' => 'x', 'url' => '/']))->via($absent))
        ->toBe(['database']);
});

it('never tells a Sunday joiner they missed a week they could not play', function () {
    [$slate] = resultsSlate([3, 1]);

    $latecomer = pickemAdmin();
    GroupMember::factory()->create([
        'group_id' => $slate->contest->group_id,
        'user_id' => $latecomer->id,
        // After the card went up.
        'created_at' => '2026-09-13 09:00:00',
    ]);

    settleResults($slate);

    Notification::assertNotSentTo($latecomer, SlateMissed::class);
});

it('splits a tied week and says so', function () {
    [$slate, $players] = resultsSlate([3, 3, 1]);
    [$first, $second] = $players->all();

    settleResults($slate);

    // Both tied entrants win and are both paid; the copy names the other.
    Notification::assertSentTo($first, SlateSettled::class,
        fn (SlateSettled $n) => $n->result['won'] === true
            && $n->result['others'] === '@'.$second->handle);
});

it('fans the room out on default, off the live queue', function () {
    [$slate] = resultsSlate();
    settleResults($slate);

    // Re-run the announcement alone with the bus faked to see the batch.
    $slate->fresh()->forceFill(['results_announced_at' => null])->save();
    Bus::fake();
    (new AnnounceSlateResults($slate->id))->handle();

    Bus::assertBatched(fn ($batch) => $batch->queue() === 'default');
});

it('sends the result in-job, never double-queued', function () {
    /*
     * Negative pin: SendSlateResult is already a queued job carrying the
     * batch and ThrottleMail. A ShouldQueue notification inside it would
     * double-queue the send and move the actual mail OUTSIDE the budget —
     * counting intent, not sends. Re-adding the interface fails here.
     */
    $result = ['week' => 'W', 'group' => 'G', 'winner' => 'x', 'url' => '/'];

    expect(new SlateSettled($result))->not->toBeInstanceOf(ShouldQueue::class)
        ->and(new SlateMissed($result))->not->toBeInstanceOf(ShouldQueue::class);
});

it('carries the inbox row as structured data, never as frozen copy', function () {
    /*
     * Rendering at send time would pin the register: somebody who later
     * moves to PG would still be shown the PG-13 line in their own inbox.
     */
    [$slate, $players] = resultsSlate();

    settleResults($slate);

    Notification::assertSentTo($players->first(), SlateSettled::class, function (SlateSettled $n) use ($players) {
        $row = $n->toArray($players->first());

        return $row['kind'] === 'slate-results'
            && str_starts_with($row['key'], 'notify.results.')
            && is_array($row['replace'])
            && filled($row['url']);
    });
});

it('reaches everybody through the inbox, whatever else they have turned off', function () {
    // Push subscriptions are zero at launch and mail is refusable, so the
    // database channel is the only one that reaches 100% on Sep 5.
    $user = User::factory()->create([
        'pickem_notify_opt_in' => false,
        'email_verified_at' => null,
    ]);

    $result = ['won' => false, 'points' => 8, 'place' => '3rd', 'field' => 3, 'others' => '',
        'winner' => 'x', 'rival' => '', 'margin' => '0', 'beat_bear' => null, 'bear_margin' => '0',
        'week' => 'Week 2', 'group' => 'G', 'exhibition' => false, 'url' => '/'];

    expect((new SlateSettled($result))->via($user))->toBe(['database'])
        ->and((new SlateSettled($result))->via(
            tap($user)->forceFill(['pickem_notify_opt_in' => true, 'email_verified_at' => now()])
        ))->toBe(['database', 'mail']);

});
