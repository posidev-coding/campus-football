<?php

use App\Actions\PublishSlate;
use App\Enums\ContentRating;
use App\Enums\ContestMode;
use App\Jobs\SendPickReminder;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Models\User;
use App\Notifications\PickReminderNotification;
use App\Support\PickReminders;
use App\Support\Voice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;

/*
 * "YOUR PICKS ARE DUE" — the sweep, its two waves, and the stamps.
 *
 * The fact this file exists to hold: the audience roots in MEMBERSHIPS, not
 * in slate entries. An entry row is created lazily on a member's first pick,
 * so somebody who has picked nothing has no entry and no picks — and they
 * are exactly who a reminder is for. A query rooted in `slate_entries`
 * reminds only the people who already played, silently, and looks correct.
 */

beforeEach(function () {
    Notification::fake();

    // Pinned: this whole file is about windows, and GameFactory's random
    // kickoff would put a fixture inside or outside one about one run in
    // seven — passing under --filter and failing in the suite.
    $this->travelTo('2026-09-04 20:00:00');
});

/**
 * A published ten-game slate kicking off Saturday noon ET, plus the members
 * named. Admins throughout, because the `pickem` flag is admin-only until
 * launch and the sweep mirrors it.
 *
 * @return array{0: Slate, 1: Group}
 */
function reminderSlate(int $members = 1, string $kickoff = '2026-09-05 16:00:00'): array
{
    [$season, $week] = pickemSeasonWeek();
    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);

    $slate = Slate::factory()->create([
        'contest_id' => $contest->id,
        'week_id' => $week->id,
        'saturday' => '2026-09-05',
    ]);

    foreach (range(1, 10) as $i) {
        $game = pickemGame($season, $week, ['kickoff_at' => $kickoff]);
        pickemOdd($game);

        SlateGame::factory()->create([
            'slate_id' => $slate->id,
            'game_id' => $game->id,
            'position' => $i,
            'spread' => -6.5,
            'market_spread' => -6.5,
            'favorite_team_id' => $game->home_team_id,
            'odds_provider' => 'ESPN BET',
            'odds_captured_at' => '2026-09-02 09:00:00',
        ]);
    }

    $slate->update([
        'tiebreaker_slate_game_id' => $slate->games()->first()->id,
        'tiebreaker_metric' => 'combined_points',
    ]);

    app(PublishSlate::class)->handle($commissioner, $slate->fresh());

    foreach (range(1, $members) as $i) {
        GroupMember::factory()->create([
            'group_id' => $group->id,
            'user_id' => pickemAdmin()->id,
        ]);
    }

    return [$slate->fresh(), $group];
}

/** Every member of the group except the commissioner, in join order. */
function reminderMembers(Group $group)
{
    return $group->members()->where('role', '!=', GroupMember::COMMISSIONER)->get();
}

it('reminds the member who has picked NOTHING, who has no entry row at all', function () {
    /*
     * The whole design, in one test. Three members: one done, one partway,
     * one who has not touched it. The last has no `slate_entries` row and no
     * `picks` rows — invisible to any query rooted in either — and is the
     * person the feature exists for.
     */
    [$slate, $group] = reminderSlate(members: 3);
    [$done, $partway, $untouched] = reminderMembers($group)->all();

    foreach ($slate->games as $slateGame) {
        Pick::factory()->create([
            'slate_game_id' => $slateGame->id,
            'user_id' => $done->id,
            'picked_team_id' => $slateGame->favorite_team_id,
        ]);
    }

    foreach ($slate->games->take(4) as $slateGame) {
        Pick::factory()->create([
            'slate_game_id' => $slateGame->id,
            'user_id' => $partway->id,
            'picked_team_id' => $slateGame->favorite_team_id,
        ]);
    }

    expect(SlateEntry::query()->where('user_id', $untouched->id)->exists())->toBeFalse();

    $this->artisan('pickem:remind', ['--wave' => PickReminders::WAVE_REMIND])->assertSuccessful();

    Notification::assertSentTo($untouched, PickReminderNotification::class);
    Notification::assertSentTo($partway, PickReminderNotification::class);
    Notification::assertNotSentTo($done, PickReminderNotification::class);
});

it('counts only the games still PICKABLE, not the whole card', function () {
    /*
     * Picks lock game by game at kickoff. A member who picked eight of ten
     * where the other two have already started is finished — there is
     * nothing they can do, and telling them there is reads as a bug.
     */
    [$slate, $group] = reminderSlate(members: 1);
    $member = reminderMembers($group)->first();

    // Two games have already kicked; they pick the other eight.
    $started = $slate->games->take(2);
    Game::query()->whereIn('id', $started->pluck('game_id'))
        ->update(['kickoff_at' => now()->subHour()]);

    foreach ($slate->games->skip(2) as $slateGame) {
        Pick::factory()->create([
            'slate_game_id' => $slateGame->id,
            'user_id' => $member->id,
            'picked_team_id' => $slateGame->favorite_team_id,
        ]);
    }

    $this->artisan('pickem:remind', ['--wave' => PickReminders::WAVE_REMIND])->assertSuccessful();

    // Nothing for THEM. The commissioner has picked nothing and is still a
    // member who plays, so the slate is rightly still in the sweep.
    Notification::assertNotSentTo($member, PickReminderNotification::class);
});

it('still calls last orders on the late games once the early ones have kicked', function () {
    /*
     * The anchor is the next OPEN kickoff, not the first ever. Once the noon
     * games start, the first kickoff is in the past and is nobody's deadline
     * — but the 4pm card is still pickable and still worth a last call.
     * Anchoring on firstKickoff() dropped the whole slate out of the window
     * the moment its earliest game began, taking every makeable pick with it.
     */
    [$slate, $group] = reminderSlate(members: 1);
    $member = reminderMembers($group)->first();

    // Two games move to a late kickoff; the rest start in the past.
    $late = $slate->games->take(2);
    Game::query()->whereIn('id', $late->pluck('game_id'))
        ->update(['kickoff_at' => '2026-09-05 23:00:00']);
    Game::query()->whereIn('id', $slate->games->skip(2)->pluck('game_id'))
        ->update(['kickoff_at' => '2026-09-05 16:00:00']);

    // Kickoff has passed for the eight; the two late games are 90 out.
    $this->travelTo('2026-09-05 21:30:00');

    expect($slate->fresh()->load('games.game')->firstKickoff()->isPast())->toBeTrue();

    $this->artisan('pickem:remind', ['--wave' => PickReminders::WAVE_LAST_CALL])->assertSuccessful();

    Notification::assertSentTo($member, PickReminderNotification::class,
        fn (PickReminderNotification $notification) => $notification->cards[0]['owed'] === 2);
});

it('holds the window: a card more than a day out waits its turn', function () {
    [$slate] = reminderSlate(members: 1);

    // Thirty-two hours out — outside wave one's 24-hour lead.
    $this->travelTo('2026-09-04 08:00:00');

    expect(PickReminders::dueSlates(PickReminders::WAVE_REMIND))->toBeEmpty();

    // Twenty hours out, and it is due.
    $this->travelTo('2026-09-04 20:00:00');

    expect(PickReminders::dueSlates(PickReminders::WAVE_REMIND)->pluck('id')->all())
        ->toBe([$slate->id]);
});

it('reminds once across the cadence, and stamps a slate nobody owed', function () {
    [$slate, $group] = reminderSlate(members: 1);
    $member = reminderMembers($group)->first();
    $this->travelTo('2026-09-04 20:00:00');

    $this->artisan('pickem:remind', ['--wave' => PickReminders::WAVE_REMIND])->assertSuccessful();
    $this->artisan('pickem:remind', ['--wave' => PickReminders::WAVE_REMIND])->assertSuccessful();

    Notification::assertSentToTimes($member, PickReminderNotification::class, 1);
    expect($slate->fresh()->picks_reminded_at)->not->toBeNull();

    /*
     * And a slate where everybody is already done is STAMPED anyway. It is
     * still a slate the sweep has answered for; unstamped it would re-ask
     * every fifteen minutes until kickoff — "checked, nothing to send"
     * becoming "retry forever".
     */
    [$quiet] = reminderSlate(members: 0);
    $this->artisan('pickem:remind', ['--wave' => PickReminders::WAVE_REMIND])->assertSuccessful();

    expect($quiet->fresh()->picks_reminded_at)->not->toBeNull();
});

it('keeps the two waves on their own stamps, and suppresses a doubled-up last call', function () {
    [$slate, $group] = reminderSlate(members: 1);
    $member = reminderMembers($group)->first();

    // 90 minutes out: both waves would qualify on the clock alone.
    $this->travelTo('2026-09-05 15:00:00');

    $this->artisan('pickem:remind', ['--wave' => PickReminders::WAVE_REMIND])->assertSuccessful();
    expect($slate->fresh()->picks_reminded_at)->not->toBeNull()
        ->and($slate->fresh()->last_call_sent_at)->toBeNull();

    /*
     * A late-published card blows past the 24-hour window, so wave one fires
     * on the next tick — and wave two must NOT follow it ninety minutes
     * later. Two messages inside twelve hours about a card they only just
     * heard about is how a reminder becomes spam.
     */
    $this->artisan('pickem:remind', ['--wave' => PickReminders::WAVE_LAST_CALL])->assertSuccessful();
    expect($slate->fresh()->last_call_sent_at)->toBeNull();

    Notification::assertSentToTimes($member, PickReminderNotification::class, 1);

    // Once the suppression window passes, the last call is free to go.
    $slate->forceFill(['picks_reminded_at' => now()->subHours(8)])->save();
    $this->artisan('pickem:remind', ['--wave' => PickReminders::WAVE_LAST_CALL])->assertSuccessful();

    expect($slate->fresh()->last_call_sent_at)->not->toBeNull();
    Notification::assertSentToTimes($member, PickReminderNotification::class, 2);
});

it('mirrors the flag: nobody outside it is written to while it is closed', function () {
    /*
     * Until launch the flag is admin-only, so a reminder linking a non-admin
     * to their group lands them on the coming-soon screen. The sweep reads
     * the CONFIG rather than resolving Pennant per user — the database driver
     * persists a row per resolve, which is why the preflight reads it too.
     */
    [, $group] = reminderSlate(members: 0);
    $this->travelTo('2026-09-04 20:00:00');

    $civilian = User::factory()->create(['admin' => false, 'handle' => 'civilian']);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $civilian->id]);

    config(['cfb.pickem_open' => false]);
    $this->artisan('pickem:remind', ['--wave' => PickReminders::WAVE_REMIND])->assertSuccessful();
    Notification::assertNotSentTo($civilian, PickReminderNotification::class);

    // Open the flag and the same member is in the audience.
    Slate::query()->update(['picks_reminded_at' => null]);
    config(['cfb.pickem_open' => true]);
    $this->artisan('pickem:remind', ['--wave' => PickReminders::WAVE_REMIND])->assertSuccessful();

    Notification::assertSentTo($civilian, PickReminderNotification::class);
});

it('says nothing to anyone MakePick would refuse', function () {
    [, $group] = reminderSlate(members: 0);
    $this->travelTo('2026-09-04 20:00:00');

    $unverified = User::factory()->unverified()->create(['admin' => true, 'handle' => 'ghost']);
    $handleless = User::factory()->create(['admin' => true, 'handle' => null]);

    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $unverified->id]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $handleless->id]);

    $this->artisan('pickem:remind', ['--wave' => PickReminders::WAVE_REMIND])->assertSuccessful();

    Notification::assertNotSentTo($unverified, PickReminderNotification::class);
    Notification::assertNotSentTo($handleless, PickReminderNotification::class);
});

it('sends nothing and stamps nothing on a dry run', function () {
    [$slate, $group] = reminderSlate(members: 1);
    $this->travelTo('2026-09-04 20:00:00');

    $this->artisan('pickem:remind', ['--dry' => true])->assertSuccessful();

    Notification::assertNothingSent();
    expect($slate->fresh()->picks_reminded_at)->toBeNull();
});

it('drops a reminder the mail budget released past kickoff', function () {
    /*
     * ThrottleMail RELEASES rather than drops, so a reminder can sit in the
     * queue until tomorrow. A digest arriving late is merely late; a pick
     * reminder arriving after kickoff tells somebody to do a thing the app
     * will refuse, about games already played. The job re-reads live rather
     * than trusting its payload, so this shrinks to silence.
     */
    [$slate, $group] = reminderSlate(members: 1);
    $member = reminderMembers($group)->first();

    $job = new SendPickReminder($member->id, [$slate->id], PickReminders::WAVE_REMIND);

    // The queue drained late: every game has kicked off.
    $this->travelTo('2026-09-05 17:00:00');
    $job->handle();

    Notification::assertNothingSent();
});

it('drops a reminder for somebody who left the group in between', function () {
    [$slate, $group] = reminderSlate(members: 1);
    $member = reminderMembers($group)->first();

    $job = new SendPickReminder($member->id, [$slate->id], PickReminders::WAVE_REMIND);

    GroupMember::query()->where('group_id', $group->id)->where('user_id', $member->id)->delete();
    $job->handle();

    Notification::assertNothingSent();
});

it('carries mail and push, and holds SMS behind its switch', function () {
    $member = pickemAdmin();
    $cards = [['slate_id' => 1, 'group' => 'Vol Nation', 'owed' => 3, 'total' => 10, 'when' => 'Sat 12:00pm', 'url' => '/']];

    $notification = new PickReminderNotification($cards, PickReminders::WAVE_REMIND);

    // No device subscribed yet: mail alone carries it.
    expect($notification->via($member))->toBe(['mail']);

    $member->updatePushSubscription('https://push.example/abc', 'p256dh-key', 'auth-token');
    expect($notification->via($member->fresh()))->toBe(['mail', WebPushChannel::class]);

    // Opting out of the pick'em list drops mail and nothing else.
    $member->forceFill(['pickem_notify_opt_in' => false])->save();
    expect($notification->via($member->fresh()))->toBe([WebPushChannel::class]);
});

it('sends the reminder in-job, never double-queued', function () {
    /*
     * Negative pin: SendPickReminder is already a queued job carrying the
     * batch and both budget middlewares. A ShouldQueue notification inside
     * it would double-queue the send, move the actual mail OUTSIDE
     * ThrottleMail, and outlive the staleness re-check the job exists for.
     * Re-adding the interface fails here.
     */
    expect(new PickReminderNotification([], PickReminders::WAVE_REMIND))
        ->not->toBeInstanceOf(ShouldQueue::class);
});

it('speaks the reader\'s register, with no authenticated user to fall back on', function () {
    /*
     * Deliberately no actingAs. These render inside a queued job where
     * Voice::line()'s auth() fallback is null, so `for:` is the only thing
     * between a PG reader and the PG-13 line — and a missing one is silent.
     */
    $pg = User::factory()->make(['content_rating' => ContentRating::Pg, 'first_name' => 'Sam']);
    $r = User::factory()->make(['content_rating' => ContentRating::R, 'first_name' => 'Sam']);

    $cards = [['slate_id' => 1, 'group' => 'Vol Nation', 'owed' => 3, 'total' => 10, 'when' => 'Sat 12:00pm', 'url' => '/picks']];
    $notification = new PickReminderNotification($cards, PickReminders::WAVE_REMIND);

    $pgBody = $notification->toMail($pg)->introLines[0];
    $rBody = $notification->toMail($r)->introLines[0];

    expect($pgBody)->toBe(Voice::line('notify.reminder.body', ['owed' => '3', 'total' => '10', 'count' => '1', 'group' => 'Vol Nation', 'when' => 'Sat 12:00pm'], for: $pg))
        ->and($pgBody)->not->toBe($rBody)
        ->and($pgBody)->toContain('3')
        ->and($pgBody)->not->toContain(':owed');
});

it('folds several cards into one message rather than one each', function () {
    /*
     * Nothing caps how many contests a person joins. Three separate emails
     * on a Friday, from a domain that also carries password resets, is a
     * spam complaint waiting to happen.
     */
    $member = pickemAdmin();
    [$first] = reminderSlate(members: 0);
    [$second] = reminderSlate(members: 0);

    foreach ([$first, $second] as $slate) {
        GroupMember::factory()->create([
            'group_id' => $slate->contest->group_id,
            'user_id' => $member->id,
        ]);
    }

    $this->travelTo('2026-09-04 20:00:00');
    $this->artisan('pickem:remind', ['--wave' => PickReminders::WAVE_REMIND])->assertSuccessful();

    Notification::assertSentToTimes($member, PickReminderNotification::class, 1);

    Notification::assertSentTo($member, PickReminderNotification::class,
        fn (PickReminderNotification $notification) => count($notification->cards) === 2);
});
