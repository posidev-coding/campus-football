<?php

use App\Enums\ContentRating;
use App\Models\FeedRun;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use App\Notifications\KickoffAlertNotification;
use App\Support\Voice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * The kickoff sweep: every five minutes, every unstamped game inside the
 * fifteen-minute window, everyone with a subscription following either
 * side. Every kickoff here is PINNED — GameFactory's random window is a
 * suite-flake source, and this file's whole subject is the window.
 */
function kickoffFixture(int $minutesOut = 10): array
{
    $vols = Team::factory()->create([
        'location' => 'Tennessee', 'display_name' => 'Tennessee Volunteers',
        'slug' => 'tennessee-volunteers', 'abbreviation' => 'TENN', 'alt_color' => 'ffffff',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $vols->id,
        'kickoff_at' => now()->addMinutes($minutesOut),
        'name' => 'Away Team at Tennessee',
        'completed' => false,
    ]);

    $follower = User::factory()->create();
    $follower->followedTeams()->attach([$vols->id => ['position' => 1]]);
    $follower->updatePushSubscription('https://push.example.test/send/vols-fan', 'key', 'token');

    return [$game, $vols, $follower];
}

describe('who gets one', function () {
    it('alerts a subscribed follower of either side, once, and stamps the game', function () {
        Notification::fake();

        [$game, , $follower] = kickoffFixture();

        $this->artisan('cfb:kickoff-alerts')->assertSuccessful();

        Notification::assertSentTo($follower, KickoffAlertNotification::class);

        expect($game->fresh()->kickoff_alert_sent_at)->not->toBeNull();

        // The rerun the five-minute cadence guarantees: the stamp, not
        // luck, is what keeps this a single send.
        $this->artisan('cfb:kickoff-alerts')->assertSuccessful();

        Notification::assertSentToTimes($follower, KickoffAlertNotification::class, 1);
    });

    it('skips followers without a subscription and subscribers without the follow', function () {
        Notification::fake();

        [, $vols] = kickoffFixture();

        $unsubscribed = User::factory()->create();
        $unsubscribed->followedTeams()->attach([$vols->id => ['position' => 1]]);

        $stranger = User::factory()->create();
        $stranger->updatePushSubscription('https://push.example.test/send/stranger', 'key', 'token');

        $this->artisan('cfb:kickoff-alerts')->assertSuccessful();

        Notification::assertNotSentTo($unsubscribed, KickoffAlertNotification::class);
        Notification::assertNotSentTo($stranger, KickoffAlertNotification::class);
    });

    it('alerts two games in one window about their own game, one users query for both', function () {
        /*
         * The noon slate is ~15 games in one sweep. Recipients are fetched
         * once for the whole window and matched per game in PHP — the
         * per-game users query was 15 scans with two EXISTS subqueries
         * each, every five minutes. What must survive the collapse: each
         * follower hears about THEIR game, named for THEIR team.
         */
        Notification::fake();

        [$gameA] = kickoffFixture();

        $bears = Team::factory()->create([
            'location' => 'Baylor', 'display_name' => 'Baylor Bears',
            'slug' => 'baylor-bears', 'abbreviation' => 'BAY', 'alt_color' => 'ffffff',
        ]);
        $gameB = Game::factory()->create([
            'home_team_id' => $bears->id,
            'kickoff_at' => now()->addMinutes(12),
            'name' => 'Away Team at Baylor',
            'completed' => false,
        ]);
        $bearsFan = User::factory()->create();
        $bearsFan->followedTeams()->attach([$bears->id => ['position' => 1]]);
        $bearsFan->updatePushSubscription('https://push.example.test/send/bears-fan', 'key', 'token');

        $this->artisan('cfb:kickoff-alerts')->assertSuccessful();

        Notification::assertSentToTimes($bearsFan, KickoffAlertNotification::class, 1);
        Notification::assertSentTo($bearsFan, KickoffAlertNotification::class,
            function (KickoffAlertNotification $notification) use ($bearsFan, $gameB) {
                $push = $notification->toWebPush($bearsFan)->toArray();

                return $push['title'] === 'Away Team at Baylor'
                    && str_contains($push['body'], 'Baylor')
                    && $push['tag'] === "kickoff-{$gameB->id}";
            });

        expect($gameA->fresh()->kickoff_alert_sent_at)->not->toBeNull()
            ->and($gameB->fresh()->kickoff_alert_sent_at)->not->toBeNull();
    });

    it('ignores games outside the window and games already played', function () {
        Notification::fake();

        [, , $follower] = kickoffFixture(minutesOut: 45);

        $this->artisan('cfb:kickoff-alerts')->assertSuccessful();

        Notification::assertNothingSent();
    });

    it('stamps a game nobody reachable follows, so it is never retried forever', function () {
        Notification::fake();

        $game = Game::factory()->create([
            'kickoff_at' => now()->addMinutes(10),
            'completed' => false,
        ]);

        $this->artisan('cfb:kickoff-alerts')->assertSuccessful();

        Notification::assertNothingSent();

        expect($game->fresh()->kickoff_alert_sent_at)->not->toBeNull();
    });
});

describe('the run itself', function () {
    it('sends nothing and stamps nothing on --dry', function () {
        Notification::fake();

        [$game] = kickoffFixture();

        $this->artisan('cfb:kickoff-alerts', ['--dry' => true])->assertSuccessful();

        Notification::assertNothingSent();

        expect($game->fresh()->kickoff_alert_sent_at)->toBeNull();
    });

    it('queues, and writes the run ledger', function () {
        Notification::fake();

        kickoffFixture();

        $this->artisan('cfb:kickoff-alerts')->assertSuccessful();

        expect(new KickoffAlertNotification(Game::factory()->create(), 'Tennessee'))
            ->toBeInstanceOf(ShouldQueue::class)
            ->and(FeedRun::where('command', 'kickoff-alerts')->where('status', FeedRun::COMPLETE)->exists())
            ->toBeTrue();
    });
});

describe('the voice', function () {
    it('speaks the kickoff body in every register, escalating, replacements replaced', function () {
        /*
         * NO actingAs, deliberately: these render from a queued job where
         * line()'s auth fallback is null, so the `for:` argument is the
         * only thing between a PG reader and the PG-13 line.
         */
        $replace = ['team' => 'Tennessee'];

        $pg = Voice::line('push.kickoff.body', $replace, User::factory()->make(['content_rating' => ContentRating::Pg]));
        $r = Voice::line('push.kickoff.body', $replace, User::factory()->make(['content_rating' => ContentRating::R]));

        expect($pg)->not->toBe('')
            ->and($r)->not->toBe('')
            ->and($r)->not->toBe($pg)
            ->and($pg)->toContain('Tennessee')
            ->and($pg)->not->toContain(':team');
    });

    it('keeps the welcome and banner lines escalating too', function () {
        foreach (['push.welcome.title', 'push.welcome.body', 'push.banner.heading', 'push.banner.body', 'push.banner.confirmed'] as $key) {
            $pg = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::Pg]));
            $r = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::R]));

            expect($pg)->not->toBe('')
                ->and($r)->not->toBe('');
        }
    });
});
