<?php

use App\Actions\MakePick;
use App\Actions\PublishSlate;
use App\Actions\RecordUxEvent;
use App\Enums\UxSignal;
use App\Models\ActivityEvent;
use App\Models\FeedRun;
use App\Models\Team;
use App\Models\User;
use App\Models\UxEvent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
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

        // Yesterday is stated (a zero for every signal); today never is.
        expect(UxEvent::where('day', '2026-09-05')->count())->toBe(0)
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

        expect(UxEvent::where('signal', 'retired_signal')->exists())->toBeFalse()
            ->and(UxEvent::where('signal', 'invite_opened')->sole()->count)->toBe(1)
            ->and(UxEvent::count())->toBe(count(UxSignal::cases()));
    });

    it('writes a zero row for every signal it knows, so a new counter has a first day on record', function () {
        /*
         * A table that only held the non-zero days could not tell "counted
         * zero" from "was not counting yet". onboarding_credentials_reached
         * shipped on 2026-08-31 and read 0 beside a seven-day 163 opened —
         * filed as the wizard losing everybody, when the counter had been
         * live for two of the seven days. A zero on a day the code was
         * counting is a true count, not a backfill; the snapshot reads the
         * earliest row as the day the signal started counting.
         */
        app(RecordUxEvent::class)->handle(UxSignal::InviteOpened, now()->subDay());

        $this->artisan('cfb:ux-rollup')->assertSuccessful();

        $rows = UxEvent::where('day', '2026-09-04')->pluck('count', 'signal')->all();

        expect(array_keys($rows))->toEqualCanonicalizing(array_map(fn (UxSignal $s) => $s->value, UxSignal::cases()))
            ->and($rows['invite_opened'])->toBe(1)
            ->and($rows['onboarding_credentials_reached'])->toBe(0);
    });

    it('states a day on which nothing fired at all, a zero for every signal', function () {
        /*
         * A day enters Redis only when somebody fires a signal, and the
         * rollup used to walk Redis alone — so the Sunday and Monday after a
         * slate (2026-11-08 and 2026-11-09), when nobody fired anything, got
         * no row, and funnel_since slid forward as if all ten signals had
         * shipped on the Tuesday. Redis is empty here: nothing was counted,
         * while a page view proves the same Redis was taking writes all along.
         */
        ActivityEvent::factory()->create(['occurred_at' => '2026-09-04 16:00:00']);

        $this->artisan('cfb:ux-rollup')->assertSuccessful();

        $rows = UxEvent::where('day', '2026-09-04')->pluck('count', 'signal')->all();

        expect(array_keys($rows))->toEqualCanonicalizing(array_map(fn (UxSignal $s) => $s->value, UxSignal::cases()))
            ->and(array_unique(array_values($rows)))->toBe([0]);
    });

    it('states only the day that just finished, and never backfills an older one', function () {
        // A zero written today for a day no run was there to read would be
        // an invented measurement. The snapshot reports that day missing —
        // even when the clickstream could vouch for it.
        ActivityEvent::factory()->create(['occurred_at' => '2026-09-02 16:00:00']);
        ActivityEvent::factory()->create(['occurred_at' => '2026-09-04 16:00:00']);

        $this->artisan('cfb:ux-rollup')->assertSuccessful();

        expect(UxEvent::query()->distinct()->pluck('day')->map->toDateString()->all())->toBe(['2026-09-04']);
    });

    it('never turns a rolled count back into a zero', function () {
        app(RecordUxEvent::class)->handle(UxSignal::SlateEntered, now()->subDay());

        $this->artisan('cfb:ux-rollup')->assertSuccessful();
        $this->artisan('cfb:ux-rollup')->assertSuccessful();

        expect(UxEvent::where('signal', 'slate_entered')->sole()->count)->toBe(1)
            ->and(UxEvent::where('day', '2026-09-04')->count())->toBe(count(UxSignal::cases()));
    });

    it('writes no zeros when Redis could not be read', function () {
        // An unreachable store is a failed run on the record, not a day on
        // which nobody did anything.
        $this->mock(RecordUxEvent::class)
            ->shouldReceive('rollUp')
            ->andThrow(new RuntimeException('Connection refused'));

        expect(fn () => $this->artisan('cfb:ux-rollup')->run())->toThrow(RuntimeException::class);

        expect(UxEvent::count())->toBe(0)
            ->and(FeedRun::latestFor('ux:rollup')->status)->toBe(FeedRun::FAILED);
    });

    it('leaves a day missing when the recorder could not write all day', function () {
        /*
         * The recorder swallows every failure, so a day-long stall on the
         * pulse connection leaves the same empty Redis as a quiet day — and
         * by 04:55 Redis is back and the read succeeds. Ten zeros here would
         * be a measurement nobody took. The stall drops the clickstream's
         * writes too, so nothing vouches for the day and it stays absent.
         */
        $redis = app('redis');
        Redis::shouldReceive('connection')->andThrow(new RuntimeException('Connection timed out'));

        app(RecordUxEvent::class)->handle(UxSignal::SlateEntered, now()->subDay());
        app(RecordUxEvent::class)->handle(UxSignal::FirstPickMade, now()->subDay());

        Redis::swap($redis);

        $this->artisan('cfb:ux-rollup')->assertSuccessful();

        expect(UxEvent::where('day', '2026-09-04')->exists())->toBeFalse()
            ->and(FeedRun::latestFor('ux:rollup')->status)->toBe(FeedRun::COMPLETE);
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
            'onboarding_credentials_reached',
            'onboarding_registered',
            'onboarding_team_picked',
            'onboarding_skipped',
            'tour_dismissed',
            'picks_tour_dismissed',
            'invite_opened',
            'slate_entered',
            'first_pick_made',
        ]);
    });
});

describe('the flows that emit', function () {
    it('counts a registration through the wizard, once and not twice', function () {
        // Exactly one: the wizard's own screen no longer emits, the Registered
        // listener does, and a two here would mean both are.
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

    it('counts a registration through the standalone form too', function () {
        /*
         * The bug this pins shut: `onboarding_registered` was emitted by the
         * overlay wizard alone, so every account made through /register or
         * the header's Sign up button was invisible, and the step read as
         * wizard completions rather than registrations.
         */
        Livewire::test('auth.register')
            ->set('first_name', 'Dolly')
            ->set('last_name', 'Parton')
            ->set('email', 'dolly@example.test')
            ->set('password', 'password-1234')
            ->set('password_confirmation', 'password-1234')
            ->call('register')
            ->assertHasNoErrors();

        expect(funnelCounts()['onboarding_registered'] ?? 0)->toBe(1);
    });

    it('counts nothing when a registration fails validation', function () {
        // No account was created, so there is no registration to count. The
        // signal has to sit past the validate() call, not before it.
        Livewire::test('auth.register')
            ->set('first_name', 'Dolly')
            ->set('last_name', 'Parton')
            ->set('email', 'not-an-email')
            ->set('password', 'password-1234')
            ->set('password_confirmation', 'password-4321')
            ->call('register')
            ->assertHasErrors(['email', 'password']);

        // The form's own arrival is counted on load (CFB-110) — they did
        // open the door and see the credentials. The registration is not.
        expect(funnelCounts())->toBe([
            'onboarding_opened' => 1,
            'onboarding_credentials_reached' => 1,
        ]);
    });

    it('emits the registration signal from exactly one place', function () {
        /*
         * Two components emitting one funnel signal is how this drifted the
         * first time — the count silently became a count of something else.
         * The listener is the only emitter; a screen reaching for this signal
         * again fails here rather than in a report six weeks later.
         */
        $emitters = collect(File::allFiles(base_path('app')))
            ->merge(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => str_contains($file->getContents(), 'UxSignal::OnboardingRegistered')
                // NAMING the signal is not emitting it. `AnalyticsCatalog`
                // READS this counter to size a cohort week against, and it is
                // supposed to — the funnel's own count is the denominator
                // there. An emitter is a file that reaches for the RECORDER,
                // which is the thing that can double a total.
                && str_contains($file->getContents(), 'RecordUxEvent'))
            ->map(fn ($file) => str_replace(base_path().'/', '', $file->getPathname()))
            ->values()
            ->all();

        expect($emitters)->toBe(['app/Listeners/CountRegistration.php']);
    });

    it('counts a guest who opens the wizard, and not one who merely loads Home', function () {
        /*
         * The bug this pins shut: the signal was emitted from the wizard's
         * `mount()`, and Home renders the wizard for everybody — so a week of
         * "starts" was 201 guest PAGE LOADS against 5 registrations, and the
         * one number the funnel exists to produce (of the people who began,
         * how many finished) was not measured at all. Pressing the front door
         * is the boundary; loading a page that carries it is not.
         */
        $this->get(route('home'))->assertOk();

        expect(funnelCounts())->toBe([]);

        Livewire::test('onboarding')->call('begin');

        expect(funnelCounts())->toBe(['onboarding_opened' => 1]);
    });

    it('counts one start however often a guest reopens the wizard', function () {
        // The X and Escape both close the overlay, and the next Home load
        // mounts it again — reopening is not a second signup.
        Livewire::test('onboarding')->call('begin')->call('begin');
        Livewire::test('onboarding')->call('begin');

        expect(funnelCounts())->toBe(['onboarding_opened' => 1]);
    });

    it('counts nothing when a signed-in member opens the same overlay', function () {
        // Same component, different errand: for them it is the favorite-team
        // moment, and there is no signup to be at the top of.
        Livewire::actingAs(User::factory()->create())
            ->test('onboarding')
            ->call('begin');

        expect(funnelCounts())->toBe([]);
    });

    it('counts a guest reaching the credentials pane, once however often they back up', function () {
        /*
         * The step this was added for: a week read 225 opened against 5
         * registered with NOTHING in between, so the 220 who left could have
         * been balking at their own name, at the trash-talk register, or at
         * the password rules, and the product could not tell which. Arriving
         * at the email and password form is the boundary that splits those.
         *
         * Deduped like `onboarding_opened`, on the same session hash: backing
         * up to fix a typo and coming forward again is one arrival, or the
         * numerator inflates and this step reads better than the truth.
         */
        Livewire::test('onboarding')
            ->set('first_name', 'Dolly')->set('last_name', 'Parton')
            ->call('next')->assertSet('step', 'rating')
            ->call('next')->assertSet('step', 'credentials')
            ->call('back')->assertSet('step', 'rating')
            ->call('next')->assertSet('step', 'credentials');

        expect(funnelCounts())->toBe(['onboarding_credentials_reached' => 1]);
    });

    it('counts nothing for a guest who never gets past the first question', function () {
        // The other half of the same pair, and the half that makes it
        // actionable: they left before anything was asked of them.
        Livewire::test('onboarding')
            ->call('next')
            ->assertHasErrors(['first_name', 'last_name'])
            ->assertSet('step', 'name');

        expect(funnelCounts())->toBe([]);
    });

    it('counts nothing when a signed-in member walks the same pane', function () {
        // Same component, different errand — for them the overlay is the
        // favorite-team moment, and there is no signup to be halfway through.
        // Posted straight at 'rating', because a member's own mount() opens on
        // the picker and could never reach this by hand.
        Livewire::actingAs(User::factory()->create())
            ->test('onboarding')
            ->set('step', 'rating')
            ->call('next')
            ->assertSet('step', 'credentials');

        expect(funnelCounts())->toBe([]);
    });

    it('emits the credentials-reached signal from exactly one place', function () {
        /*
         * `next()` is the only door into that pane — back() cannot reach it
         * from the picker, which sits past registration, and register() is
         * the only way out. A second emitter would make this a count of
         * something other than what it is named after, exactly as
         * `onboarding_registered` once was.
         */
        $emitters = collect(File::allFiles(base_path('app')))
            ->merge(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => str_contains($file->getContents(), 'UxSignal::OnboardingCredentialsReached'))
            ->map(fn ($file) => str_replace(base_path().'/', '', $file->getPathname()))
            ->values()
            ->all();

        // ONE file, which both signup doors use (CFB-110): the wizard and the
        // /register form count through the same guards, or the two doors
        // count two populations again.
        expect($emitters)->toBe(['app/Livewire/Concerns/CountsSignupFunnel.php']);
    });

    it('emits the opened signal from exactly one place', function () {
        $emitters = collect(File::allFiles(base_path('app')))
            ->merge(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => str_contains($file->getContents(), 'UxSignal::OnboardingOpened'))
            ->map(fn ($file) => str_replace(base_path().'/', '', $file->getPathname()))
            ->values()
            ->all();

        expect($emitters)->toBe(['app/Livewire/Concerns/CountsSignupFunnel.php']);
    });

    it('counts an invite link being opened, by a guest', function () {
        // The top of the acquisition funnel, and the reason /join is public.
        [, $group] = pickemContest();
        config(['cfb.pickem_open' => true]);

        $this->get(route('pickem.join', ['code' => $group->code]))->assertOk();

        expect(funnelCounts()['invite_opened'] ?? 0)->toBe(1);
    });

    it('counts a slate entry only for a member who has not entered yet', function () {
        /*
         * THE DENOMINATOR AND THE NUMERATOR MUST COUNT ONE POPULATION.
         * `first_pick_made` fires once per (user, slate) for all time, but
         * the entry dedupe is only per DAY, so before the guard a member who
         * picked on Saturday and reopened their sheet on Sunday added a
         * second open that no pick could ever answer — the reported
         * pick-through FELL as engagement rose, and a real week read 48%.
         *
         * The clock is travelled rather than the Redis keys touched, so the
         * day-scoped dedupe is exercised exactly as it is written.
         */
        [$slate, $alice] = pickemContestants();
        $group = $slate->contest->group;

        $open = fn () => Livewire::actingAs($alice)
            ->withQueryParams(['view' => 'slate'])
            ->test('group', ['group' => $group]);

        $open();

        app(MakePick::class)->handle(
            $alice,
            $slate->games()->with('game')->first(),
            $slate->games()->with('game')->first()->game->home_team_id,
        );

        // Sunday in Knoxville: a new funnel day, the same pick'em Saturday.
        $this->travelTo('2026-09-06 18:00:00');

        $open();

        expect(funnelCounts('2026-09-05'))->toBe(['slate_entered' => 1, 'first_pick_made' => 1])
            ->and(funnelCounts('2026-09-06'))->toBe([]);
    });

    it('still counts the member who opens a slate and never picks', function () {
        // The abandonment the rate exists to find. An over-tight guard would
        // collapse the denominator onto the numerator and report 100%.
        [$slate, , $bob] = pickemContestants();

        Livewire::actingAs($bob)
            ->withQueryParams(['view' => 'slate'])
            ->test('group', ['group' => $slate->contest->group]);

        expect(funnelCounts())->toBe(['slate_entered' => 1]);
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

describe('one population, one order', function () {
    /*
     * CFB-110: a week read opened 0, credentials 0, registered 1 with every
     * signal counting the full seven days. The registration came through
     * /register, which the Registered listener counts and the two steps
     * above it never did — two counters measuring two populations. Every
     * door now counts every step, so the order holds by construction.
     */
    function onboardingFunnel(): array
    {
        $counts = funnelCounts();

        return array_map(fn (string $signal) => $counts[$signal] ?? 0, [
            'opened' => 'onboarding_opened',
            'credentials' => 'onboarding_credentials_reached',
            'registered' => 'onboarding_registered',
            'team_picked' => 'onboarding_team_picked',
            'skipped' => 'onboarding_skipped',
        ]);
    }

    /** A guest walking the overlay wizard up to and through register(). */
    function walkTheWizard(): void
    {
        Livewire::test('onboarding')
            ->call('begin')
            ->set('first_name', 'Dolly')->set('last_name', 'Parton')
            ->call('next')->assertSet('step', 'rating')
            ->call('next')->assertSet('step', 'credentials')
            ->set('email', 'dolly@example.test')
            ->set('password', 'password-1234')
            ->set('password_confirmation', 'password-1234')
            ->call('register')
            ->assertHasNoErrors();
    }

    it('moves the five counters in order through the wizard and a team pick', function () {
        Queue::fake();
        Team::factory()->create(['id' => 2633]);

        walkTheWizard();

        expect(onboardingFunnel())->toBe(['opened' => 1, 'credentials' => 1, 'registered' => 1, 'team_picked' => 0, 'skipped' => 0]);

        Livewire::actingAs(User::where('email', 'dolly@example.test')->sole())
            ->test('onboarding')
            ->call('addTeam', 2633);

        expect(onboardingFunnel())->toBe(['opened' => 1, 'credentials' => 1, 'registered' => 1, 'team_picked' => 1, 'skipped' => 0]);
    });

    it('still counts the open on the skip path', function () {
        walkTheWizard();

        Livewire::actingAs(User::where('email', 'dolly@example.test')->sole())
            ->test('onboarding')
            ->call('done');

        expect(onboardingFunnel())->toBe(['opened' => 1, 'credentials' => 1, 'registered' => 1, 'team_picked' => 0, 'skipped' => 1]);
    });

    it('counts the /register door on the same funnel, in the same order', function () {
        // The case the card was filed on: one signup, through the form, that
        // skipped the team moment.
        $this->get(route('register'))->assertOk();

        expect(onboardingFunnel())->toBe(['opened' => 1, 'credentials' => 1, 'registered' => 0, 'team_picked' => 0, 'skipped' => 0]);

        Livewire::test('auth.register')
            ->set('first_name', 'Dolly')
            ->set('last_name', 'Parton')
            ->set('email', 'dolly@example.test')
            ->set('password', 'password-1234')
            ->set('password_confirmation', 'password-1234')
            ->call('register')
            ->assertHasNoErrors();

        Livewire::actingAs(User::where('email', 'dolly@example.test')->sole())
            ->test('onboarding')
            ->call('done');

        expect(onboardingFunnel())->toBe(['opened' => 1, 'credentials' => 1, 'registered' => 1, 'team_picked' => 0, 'skipped' => 1]);
    });

    it('counts a guest once who opens the wizard and then takes the form instead', function () {
        // One browser, one session hash, both doors: one arrival.
        Livewire::test('onboarding')->call('begin');
        Livewire::test('auth.register');
        Livewire::test('auth.register');

        expect(onboardingFunnel()['opened'])->toBe(1)
            ->and(onboardingFunnel()['credentials'])->toBe(1);
    });

    it('counts no open for software loading the form', function () {
        // A crawler never runs the wizard's JavaScript, so the form's GET has
        // to refuse it too, or the two doors count different populations.
        $this->withHeader('User-Agent', 'Googlebot/2.1 (+http://www.google.com/bot.html)')
            ->get(route('register'))
            ->assertOk();

        expect(funnelCounts())->toBe([]);
    });

    it('counts a team pick once per account, not again when a skipper adds a team later', function () {
        /*
         * A member who skipped comes back through the zero-teams CTA and
         * picks. Counted again, one person read as a skip AND a pick, and
         * the two ways out of one moment summed past the registrations.
         */
        Queue::fake();
        Team::factory()->create(['id' => 2633]);

        $user = User::factory()->create();

        Livewire::actingAs($user)->test('onboarding')->call('done');
        Livewire::actingAs($user->fresh())->test('onboarding')->call('addTeam', 2633);

        // The pick landed — this is not passing on a team that was not there.
        expect($user->followedTeams()->pluck('teams.id')->all())->toBe([2633])
            ->and(onboardingFunnel())->toBe(['opened' => 0, 'credentials' => 0, 'registered' => 0, 'team_picked' => 0, 'skipped' => 1]);
    });
});
