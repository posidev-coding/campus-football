<?php

namespace App\Notifications;

use App\Support\Brand;
use App\Support\Voice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * Branded, queued replacement for the framework's VerifyEmail — same reasoning
 * as ResetPasswordNotification, with one addition.
 *
 * This is the first thing a new account receives, so it is the one transactional
 * email that gets to have a personality. It speaks through `Voice`, and it
 * passes `$for:` EXPLICITLY: `Voice::line()` falls back to `auth()->user()`,
 * which is null inside a queued job, so an omitted argument would silently
 * render every reader the PG-13 line regardless of what they chose at signup.
 *
 * The verify URL is built exactly as the framework builds it — a temporary
 * signed route over the id and a sha1 of the email — so `EmailVerificationRequest`
 * validates it unchanged and nothing about the receiving end has to know this
 * class exists.
 */
class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your email — '.Brand::name())
            ->greeting('Welcome, '.$notifiable->first_name.'.')
            ->line(Voice::line('mail.verify.intro', for: $notifiable))
            ->line(Voice::line('mail.verify.reward', for: $notifiable))
            ->action('Confirm my email', $this->verificationUrl($notifiable))
            ->line('If you did not create an account, you can ignore this and nothing happens.')
            ->salutation('— '.Brand::name());
    }

    /**
     * The framework's own signature, reproduced rather than reinvented: change
     * the parameters and the stock EmailVerificationRequest stops validating.
     */
    private function verificationUrl(object $notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}
