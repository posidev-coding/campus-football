<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\Brand;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Transport\CloudflareTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

describe('password reset', function () {
    it('sends our own notification, not the framework template', function () {
        Notification::fake();

        $user = User::factory()->create();

        Password::sendResetLink(['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertNotSentTo($user, ResetPassword::class);
    });

    it('is queued, so a form submit does not wait on SMTP', function () {
        /*
         * The framework's own ResetPassword is NOT ShouldQueue, so before this
         * existed the send happened inside the web request. Against the `log`
         * mailer that is invisible; behind Brevo it is a network handshake the
         * user sits through after pressing a button.
         *
         * Asserted on the CONTRACT rather than by timing a request, because
         * phpunit pins QUEUE_CONNECTION=sync — a queued job runs inline here,
         * so assertPushed would never fire and a duration assertion would
         * measure nothing.
         */
        expect(new ResetPasswordNotification('token'))->toBeInstanceOf(ShouldQueue::class);
    });

    it('carries a link the reset screen accepts', function () {
        $user = User::factory()->create();
        $mail = (new ResetPasswordNotification('a-token'))->toMail($user);

        // The token and the email both, because password.reset validates the
        // pair — a link missing either is a link that 404s or silently fails.
        expect($mail->actionUrl)->toContain('a-token')
            ->and($mail->actionUrl)->toContain(urlencode($user->email));
    });

    it('offers no unsubscribe', function () {
        // Transactional mail is not a list. An unsubscribe control here invites
        // somebody to turn off the one email that gets their account back.
        $html = (string) (new ResetPasswordNotification('t'))->toMail(User::factory()->create())->render();

        expect($html)->not->toContain('unsubscribe');
    });
});

describe('email verification', function () {
    it('sends our own notification when an account is registered', function () {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        event(new Registered($user));

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    });

    it('is queued', function () {
        expect(new VerifyEmailNotification)->toBeInstanceOf(ShouldQueue::class);
    });

    it('builds a signed URL the stock verification request accepts', function () {
        $user = User::factory()->unverified()->create();
        $url = (new VerifyEmailNotification)->toMail($user)->actionUrl;

        /*
         * The framework's EmailVerificationRequest checks the id AND a sha1 of
         * the email. Reproducing its signature rather than inventing one is the
         * whole reason the receiving controller needed no changes.
         */
        expect($url)->toContain((string) $user->id)
            ->and($url)->toContain(sha1($user->getEmailForVerification()))
            ->and($url)->toContain('signature=');

        $this->actingAs($user)->get($url)->assertRedirect();
        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    });
});

describe('the branded template', function () {
    it('carries no SVG, because Gmail strips it', function () {
        /*
         * The app's mark is an inline <svg> everywhere else. Gmail removes svg
         * entirely, so an email reusing x-brand.mark renders a brandless header
         * — and it does it silently, in one client, which is the hardest kind
         * of regression to notice.
         */
        $html = (string) (new VerifyEmailNotification)->toMail(User::factory()->create())->render();

        expect($html)->not->toContain('<svg');
    });

    it('draws the mark from Brand, so an upload reaches the inbox too', function () {
        $html = (string) (new VerifyEmailNotification)->toMail(User::factory()->create())->render();

        expect($html)->toContain(Brand::asset('icon-192'));
    });

    it('inlines its CSS, which is the only styling an email client honours', function () {
        $html = (string) (new VerifyEmailNotification)->toMail(User::factory()->create())->render();

        // The cream paper and the action blue, both as inline style attributes
        // rather than left in the stylesheet the client will ignore.
        expect($html)->toContain('background-color: #f5f2ea')
            ->and($html)->toContain('background-color: #2563eb');
    });
});

describe('the transport', function () {
    it('resolves the Cloudflare mailer', function () {
        /*
         * The whole risk of a transport swap. A misregistered mailer fails at
         * SEND time — inside a queued job, in production — and neither local
         * (`log`) nor CI (`array`) would ever exercise it. Building a transport
         * makes no network call, so dummy credentials are enough.
         */
        config([
            'services.cloudflare.account_id' => 'test-account',
            'services.cloudflare.key' => 'test-key',
        ]);

        expect(Mail::mailer('cloudflare')->getSymfonyTransport())
            ->toBeInstanceOf(CloudflareTransport::class);
    });

    it('keeps a configured fallback, because the API transport is young', function () {
        // Cloudflare Email Service reached public beta in April 2026. With the
        // smtp mailer still defined, MAIL_MAILER=smtp is a one-variable
        // rollback rather than a deploy.
        expect(config('mail.mailers.cloudflare.transport'))->toBe('cloudflare')
            ->and(config('mail.mailers.smtp.transport'))->toBe('smtp');
    });
});
