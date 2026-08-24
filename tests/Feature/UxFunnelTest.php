<?php

use App\Actions\MakePick;
use App\Actions\PublishSlate;
use App\Actions\RecordUxEvent;
use App\Enums\UxSignal;
use App\Models\FeedRun;
use App\Models\UxEvent;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/*
 * The product funnel — the one telemetry surface no off-the-shelf APM can
 * produce, because the events are specific to this product.
 *
 * Counted in Redis on the request path and rolled up nightly, because the two
 * flows being measured (picking, onboarding) are the two most latency-
 * sensitive in the app and a row per event would put a MySQL write in both.
 *
 * These tests speak to a REAL Redis on database 15 (pinned in phpunit.xml), for
 * the same reason the suite speaks to a real MySQL: an abstraction that only
 * differs under test is where this class of bug hides.
 */

beforeEach(function () {
    Redis::connection('pulse')->flushdb();
    $this->travelTo('2026-09-05 18:00:00');
});

/** Today's counters, as the rollup would read them. */
function funnelCounts(string $day = '2026-09-05'): array
{
    return array_map('intval', (array) Redis::connection('pulse')->hgetall(RecordUxEvent::dayKey($day)));
}

describe('counting', function () {
    it('counts into Redis and never into MySQL on the request path', function () {
        app(RecordUxEvent::class)->handle(UxSignal::InviteOpened);
        app(RecordUxEvent::class)->handle(UxSignal::InviteOpened);

        expect(funnelCounts())->toBe(['invite_opened' => 2])
            ->and(UxEvent::count())->toBe(0);
    });

    it('files a signal under the league day, not the UTC one', function () {
        // 01:00 UTC Sunday is still Saturday night in Knoxville, and a
        // Saturday-night pick belongs to Saturday's funnel.
        $this->travelTo('2026-09-06 01:00:00');

        app(RecordUxEvent::class)->handle(UxSignal::FirstPickMade);

        expect(funnelCounts('2026-09-05'))->toBe(['first_pick_made' => 1])
            ->and(funnelCounts('2026-09-06'))->toBe([]);
    });

    it('counts a subject once a day and a different subject again', function () {
        // "Opened a slate" is a MOUNT, and a Livewire navigate hop re-mounts.
        // Without the dedupe the numerator inflates and the abandonment rate
        // derived from it reads worse than the truth.
        $record = app(RecordUxEvent::class);

        $record->handleOnce(UxSignal::SlateEntered, '7:1');
        $record->handleOnce(UxSignal::SlateEntered, '7:1');
        $record->handleOnce(UxSignal::SlateEntered, '7:2');

        expect(funnelCounts())->toBe(['slate_entered' => 2]);
    });

    it('is never worth a 500', function () {
        // Telemetry measures the product; it is not part of it. A pick must
        // land whatever Redis is doing.
        config(['database.redis.pulse.port' => 65_000]);
        Redis::purge('pulse');

        expect(fn () => app(RecordUxEvent::class)->handle(UxSignal::FirstPickMade))->not->toThrow(Exception::class);
        expect(fn () => app(RecordUxEvent::class)->handleOnce(UxSignal::SlateEntered, '7:1'))->not->toThrow(Exception::class);
    });
});

describe('the nightly rollup', function () {
    it('persists a finished day and clears it from Redis', function () {
        app(RecordUxEvent::class)->handle(UxSignal::InviteOpened, now()->subDay());
        app(RecordUxEvent::class)->handle(UxSignal::InviteOpened, now()->subDay());
        app(RecordUxEvent::class)->handle(UxSignal::OnboardingOpened, now()->subDay());

        $this->artisan('cfb:ux-rollup')->assertSuccessful();

        expect(UxEvent::where('signal', 'invite_opened')->sole()->count)->toBe(2)
            ->and(UxEvent::where('signal', 'onboarding_opened')->sole()->count)->toBe(1)
            ->and(funnelCounts('2026-09-04'))->toBe([]);
    });

    it('leaves today alone', function () {
        // A partial day persisted at 04:55 and again tomorrow would be right
        // only by accident.
        app(RecordUxEvent::class)->handle(UxSignal::InviteOpened);

        $this->artisan('cfb:ux-rollup')->assertSuccessful();

        expect(UxEvent::count())->toBe(0)
            ->and(funnelCounts())->toBe(['invite_opened' => 1]);
    });

    it('corrects a day it has already written instead of doubling it', function () {
        app(RecordUxEvent::class)->handle(UxSignal::InviteOpened, now()->subDay());
        $this->artisan('cfb:ux-rollup')->assertSuccessful();

        // A late count for the same day, then another pass.
        app(RecordUxEvent::class)->handle(UxSignal::InviteOpened, now()->subDay());
        app(RecordUxEvent::class)->handle(UxSignal::InviteOpened, now()->subDay());
        $this->artisan('cfb:ux-rollup')->assertSuccessful();

        expect(UxEvent::where('signal', 'invite_opened')->sole()->count)->toBe(2);
    });

    it('drops a signal the enum no longer knows and keeps the rest', function () {
        // The vocabulary is the code's, not Redis's. Written straight into
        // the hash beside a real count, so the assertion cannot pass merely
        // because the rollup did nothing.
        app(RecordUxEvent::class)->handle(UxSignal::InviteOpened, now()->subDay());
        Redis::connection('pulse')->hincrby(RecordUxEvent::dayKey('2026-09-04'), 'retired_signal', 4);

        $this->artisan('cfb:ux-rollup')->assertSuccessful();

        expect(UxEvent::pluck('count', 'signal')->all())->toBe(['invite_opened' => 1]);
    });

    it('writes a feed_runs row like every other scheduled command', function () {
        $this->artisan('cfb:ux-rollup')->assertSuccessful();

        expect(FeedRun::latestFor('ux:rollup'))->not->toBeNull();
    });
});

describe('privacy', function () {
    it('carries no identity into the store the advisor reads', function () {
        // The snapshot goes to a Claude Code routine. A funnel that carries
        // identity is a funnel that cannot be handed to anything.
        expect(Schema::getColumnListing('ux_events'))
            ->toBe(['id', 'day', 'signal', 'count', 'created_at', 'updated_at']);
    });
});

describe('the vocabulary', function () {
    it('is bounded, and abandonment is derived rather than counted', function () {
        // A third counter for a difference is a third counter that can
        // disagree with the other two.
        expect(array_map(fn (UxSignal $s) => $s->value, UxSignal::cases()))->toBe([
            'onboarding_opened',
            'onboarding_registered',
            'onboarding_team_picked',
            'onboarding_skipped',
            'tour_dismissed',
            'invite_opened',
            'slate_entered',
            'first_pick_made',
        ]);
    });
});

describe('the flows that emit', function () {
    it('counts a registration through the wizard', function () {
        Livewire::test('onboarding')
            ->set('step', 'credentials')
            ->set('first_name', 'Dolly')
            ->set('last_name', 'Parton')
            ->set('content_rating', 'pg13')
            ->set('email', 'dolly@example.test')
            ->set('password', 'password-1234')
            ->set('password_confirmation', 'password-1234')
            ->call('register');

        expect(funnelCounts()['onboarding_registered'] ?? 0)->toBe(1);
    });

    it('counts an invite link being opened, by a guest', function () {
        // The top of the acquisition funnel, and the reason /join is public.
        [, $group] = pickemContest();
        config(['cfb.pickem_open' => true]);

        $this->get(route('pickem.join', ['code' => $group->code]))->assertOk();

        expect(funnelCounts()['invite_opened'] ?? 0)->toBe(1);
    });

    it('counts only the FIRST pick on a slate', function () {
        // Keyed on the entry being new — the same fact the entry XP rides, so
        // changing picks all week counts once.
        [$commissioner, , $contest] = pickemContest();
        $commissioner->forceFill(['handle' => 'dolly', 'email_verified_at' => now()])->save();

        $slate = pickemDraftSlate($contest);
        app(PublishSlate::class)->handle($commissioner, $slate);

        $games = $slate->fresh()->games()->with('game')->get();

        app(MakePick::class)->handle($commissioner, $games[0], $games[0]->game->home_team_id);
        app(MakePick::class)->handle($commissioner, $games[1], $games[1]->game->away_team_id);

        expect(funnelCounts()['first_pick_made'] ?? 0)->toBe(1);
    });
});
