<?php

use App\Enums\ContentRating;
use App\Jobs\AnnounceSlateResults;
use App\Jobs\Middleware\ThrottleMail;
use App\Jobs\SendPickReminder;
use App\Jobs\SendSlateResult;
use App\Jobs\SendWeeklyNewsletter;
use App\Models\FeedRun;
use App\Models\User;
use App\Notifications\WeeklyNewsletter;
use App\Support\WeeklyDigest;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;

describe('who gets it', function () {
    it('queues the opted-in and skips the opted-out', function () {
        $wants = User::factory()->create(['newsletter_opt_in' => true]);
        $does_not = User::factory()->create(['newsletter_opt_in' => false]);

        $this->artisan('cfb:newsletter --dry')->assertSuccessful();

        Notification::fake();
        $this->artisan('cfb:newsletter')->assertSuccessful();

        Notification::assertSentTo($wants, WeeklyNewsletter::class);
        Notification::assertNotSentTo($does_not, WeeklyNewsletter::class);
    });

    it('records a completed zero run when nobody wants it', function () {
        // A quiet week is still a run. Without the row, the schedule panel
        // cannot tell "ran, nothing to do" from "never ran" and reads overdue
        // forever.
        $this->artisan('cfb:newsletter')->assertSuccessful();

        $run = FeedRun::where('command', 'newsletter')->latest('id')->first();

        expect($run->status)->toBe(FeedRun::COMPLETE)
            ->and((int) $run->records)->toBe(0);
    });

    it('skips an unverified address', function () {
        /*
         * An unverified address is one nobody has proved they own. Mailing it
         * weekly is how a typo at signup becomes somebody else's spam complaint
         * — and complaints are what cost a sending domain its reputation.
         */
        Notification::fake();
        $unverified = User::factory()->unverified()->create(['newsletter_opt_in' => true]);

        $this->artisan('cfb:newsletter')->assertSuccessful();

        Notification::assertNotSentTo($unverified, WeeklyNewsletter::class);
    });

    it('re-checks the preference when the JOB runs, not only when it is queued', function () {
        /*
         * A send takes minutes to drain and the throttle can push a job into
         * tomorrow. Somebody who unsubscribes in between must not still receive
         * the email their click was meant to stop — which is the case a reader
         * notices and reports as the unsubscribe being broken.
         */
        Notification::fake();
        $user = User::factory()->create(['newsletter_opt_in' => true]);

        $job = new SendWeeklyNewsletter($user->id);

        $user->forceFill(['newsletter_opt_in' => false])->save();
        $job->handle();

        Notification::assertNothingSent();
    });
});

describe('the voice', function () {
    it('speaks at the RECIPIENT rating with nobody logged in', function () {
        /*
         * The bug this exists for: Voice::line() falls back to auth()->user(),
         * which is NULL inside a queued job — so a call that forgets `for:`
         * does not error, it quietly renders the PG-13 line to everybody.
         *
         * Deliberately does NOT actingAs. Acting as the recipient would make
         * the fallback resolve to the right person by accident and the test
         * would pass while the bug was present.
         */
        expect(auth()->check())->toBeFalse();

        $r = User::factory()->create(['content_rating' => ContentRating::R]);
        $pg = User::factory()->create(['content_rating' => ContentRating::Pg]);

        $rSubject = (new WeeklyNewsletter(WeeklyDigest::for($r)))->toMail($r)->subject;
        $pgSubject = (new WeeklyNewsletter(WeeklyDigest::for($pg)))->toMail($pg)->subject;

        expect($rSubject)->toBe('Your week, and the damage report')
            ->and($pgSubject)->toBe('Your week in college football')
            ->and($rSubject)->not->toBe($pgSubject);
    });
});

describe('the pick\'em list', function () {
    it('is its own switch on Account, and stamps the same refusal', function () {
        $user = User::factory()->create([
            'newsletter_opt_in' => true,
            'pickem_notify_opt_in' => true,
        ]);

        Livewire::actingAs($user)->test('account')
            ->set('pickem_notify_opt_in', false);

        // The pick'em list goes quiet; the weekly digest does not.
        expect($user->fresh()->pickem_notify_opt_in)->toBeFalse()
            ->and($user->fresh()->newsletter_opt_in)->toBeTrue()
            // `unsubscribed_at` records that they once said no to SOMETHING.
            ->and($user->fresh()->unsubscribed_at)->not->toBeNull();
    });

    it('defaults on for a freshly created account, before any refresh', function () {
        // Mirrors the column default in $attributes: without it the instance
        // create() hands back has null here, and every caller treating it as
        // a bool is wrong.
        expect(User::factory()->create()->pickem_notify_opt_in)->toBeTrue();
    });
});

describe('unsubscribing', function () {
    it('works while signed out, which is the only state that matters', function () {
        $user = User::factory()->create(['newsletter_opt_in' => true]);

        $this->get(URL::signedRoute('newsletter.unsubscribe', ['user' => $user->id]))
            ->assertOk();

        expect($user->fresh()->newsletter_opt_in)->toBeFalse()
            ->and($user->fresh()->unsubscribed_at)->not->toBeNull();
    });

    it('silences only the list the link names', function () {
        /*
         * Two lists, two consents. Somebody who wants less mail on a Sunday
         * must not also stop being told their picks are due — one shared
         * switch would make that unsubscribe read as the app breaking.
         */
        $user = User::factory()->create([
            'newsletter_opt_in' => true,
            'pickem_notify_opt_in' => true,
        ]);

        $this->get(URL::signedRoute('newsletter.unsubscribe', [
            'user' => $user->id, 'list' => 'pickem',
        ]))->assertOk();

        expect($user->fresh()->pickem_notify_opt_in)->toBeFalse()
            ->and($user->fresh()->newsletter_opt_in)->toBeTrue();

        $this->get(URL::signedRoute('newsletter.unsubscribe', [
            'user' => $user->id, 'list' => 'newsletter',
        ]))->assertOk();

        expect($user->fresh()->newsletter_opt_in)->toBeFalse();
    });

    it('falls back to the newsletter for a link that names no list', function () {
        // Every footer link already sent points at the unnamed form.
        $user = User::factory()->create([
            'newsletter_opt_in' => true,
            'pickem_notify_opt_in' => true,
        ]);

        $this->get(URL::signedRoute('newsletter.unsubscribe', ['user' => $user->id]))
            ->assertOk();

        expect($user->fresh()->newsletter_opt_in)->toBeFalse()
            ->and($user->fresh()->pickem_notify_opt_in)->toBeTrue();
    });

    it('answers a one-click POST with a bare 200 and no CSRF token', function () {
        /*
         * RFC 8058. Gmail and Apple Mail POST this with no session, and expect
         * a 200 and nothing else — a redirect or a rendered page is what makes
         * a client report the unsubscribe as failed.
         */
        $user = User::factory()->create(['newsletter_opt_in' => true]);

        $this->post(URL::signedRoute('newsletter.unsubscribe', ['user' => $user->id]))
            ->assertOk()
            ->assertNoContent(200);

        expect($user->fresh()->newsletter_opt_in)->toBeFalse();
    });

    it('rejects a tampered signature', function () {
        $user = User::factory()->create(['newsletter_opt_in' => true]);
        $other = User::factory()->create(['newsletter_opt_in' => true]);

        $url = URL::signedRoute('newsletter.unsubscribe', ['user' => $user->id]);

        // Editing the id out of somebody else's link is the whole attack, and
        // the signature is what makes it a 403 rather than a working request.
        $this->get(str_replace("/unsubscribe/{$user->id}", "/unsubscribe/{$other->id}", $url))
            ->assertForbidden();

        expect($other->fresh()->newsletter_opt_in)->toBeTrue();
    });

    it('is idempotent, because a mail client may fire it twice', function () {
        $user = User::factory()->create(['newsletter_opt_in' => true]);
        $url = URL::signedRoute('newsletter.unsubscribe', ['user' => $user->id]);

        $this->get($url)->assertOk();
        $first = $user->fresh()->unsubscribed_at;

        $this->get($url)->assertOk();

        // The timestamp records when they FIRST said no and must not move.
        expect($user->fresh()->unsubscribed_at->timestamp)->toBe($first->timestamp);
    });

    it('rides in the headers, so the client shows its own control', function () {
        $user = User::factory()->create();
        $mail = (new WeeklyNewsletter(WeeklyDigest::for($user)))->toMail($user);

        $message = new Message(new Email);
        foreach ($mail->callbacks as $callback) {
            $callback($message->getSymfonyMessage());
        }

        $headers = $message->getSymfonyMessage()->getHeaders();

        expect($headers->has('List-Unsubscribe'))->toBeTrue()
            ->and($headers->get('List-Unsubscribe-Post')->getBodyAsString())
            ->toContain('One-Click');
    });
});

describe('the daily budget', function () {
    it('releases a job rather than sending once the budget is spent', function () {
        /*
         * Brevo's free 300/day is SHARED between marketing and transactional,
         * so an unthrottled blast can eat the allowance and leave a password
         * reset with nowhere to go. The budget sits below the ceiling and only
         * bulk mail counts against it.
         *
         * Releasing, not sleeping — the ThrottleEspn lesson: a job that sleeps
         * holds a worker, and throughput goes DOWN as workers are added.
         */
        config(['cfb.mail_daily_budget' => 1]);
        RateLimiter::clear(ThrottleMail::KEY);

        $job = new class
        {
            public int $released = 0;

            public function release($delay = 0): void
            {
                $this->released++;
            }
        };

        $middleware = new ThrottleMail;

        $sent = 0;
        $next = function () use (&$sent) {
            $sent++;
        };

        $middleware->handle($job, $next);   // spends the budget
        $middleware->handle($job, $next);   // over it

        expect($sent)->toBe(1)
            ->and($job->released)->toBe(1);
    });

    it('drains on default, off the live queue a Saturday depends on', function () {
        // `live` carries the score sweep and the just-final box score, which a
        // reader is watching — a 300-email drain must never sit ahead of it.
        Bus::fake();
        User::factory()->create(['newsletter_opt_in' => true, 'email_verified_at' => now()]);

        $this->artisan('cfb:newsletter')->assertSuccessful();

        Bus::assertBatched(fn ($batch) => $batch->queue() === 'default');
    });

    it('gives every sender the attempts a release needs to survive', function () {
        /*
         * A release still burns an attempt, so at the worker default
         * (--tries=1) the throttled tail of any send bigger than the
         * budget was deleted, not delayed. Pinned on all four senders so
         * a new one copied from an old shape fails here, loudly.
         */
        $senders = [
            new SendWeeklyNewsletter(1),
            new SendSlateResult(1, 1),
            new SendPickReminder(1, [1], 'due'),
            new AnnounceSlateResults(1),
        ];

        foreach ($senders as $job) {
            expect($job->tries)->toBe(5)
                ->and($job->timeout)->toBe(60);
        }
    });

    it('lets everything through when the budget is switched off', function () {
        config(['cfb.mail_daily_budget' => 0]);
        RateLimiter::clear(ThrottleMail::KEY);

        $sent = 0;
        (new ThrottleMail)->handle(new stdClass, function () use (&$sent) {
            $sent++;
        });

        expect($sent)->toBe(1);
    });
});
