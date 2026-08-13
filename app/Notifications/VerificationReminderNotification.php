<?php

namespace App\Notifications;

use App\Models\User;
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
 * The self-destruct warning: sent once, three days before a never-verified
 * account is pruned. `User::prunable()` REQUIRES the stamp this mail leaves
 * behind (`verification_reminded_at`), so nobody is ever deleted unwarned —
 * a mail outage pauses the countdown rather than breaking the promise.
 *
 * Queued and branded like VerifyEmailNotification, and `for:` is passed
 * explicitly for the same reason: inside a queued job there is no
 * authenticated user for Voice to fall back to.
 */
class VerificationReminderNotification extends Notification implements ShouldQueue
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
        $days = ['days' => User::VERIFICATION_REMINDER_LEAD_DAYS];

        return (new MailMessage)
            ->subject(Voice::line('mail.reminder.subject', $days, $notifiable).' — '.Brand::name())
            ->greeting('Hey, '.$notifiable->first_name.'.')
            ->line(Voice::line('mail.reminder.intro', $days, $notifiable))
            ->line(Voice::line('mail.reminder.outro', $days, $notifiable))
            ->action('Confirm my email', $this->verificationUrl($notifiable))
            ->line("If this wasn't you, do nothing — the account removes itself.")
            ->salutation('— '.Brand::name());
    }

    /**
     * The framework's own signature, reproduced exactly as
     * VerifyEmailNotification reproduces it, so the stock
     * EmailVerificationRequest validates this link unchanged.
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
