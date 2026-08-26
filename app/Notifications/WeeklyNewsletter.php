<?php

namespace App\Notifications;

use App\Support\Brand;
use App\Support\RecapWriter;
use App\Support\Voice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * The weekly email.
 *
 * NOT `ShouldQueue`, and that is deliberate: it is sent from inside
 * SendWeeklyNewsletter, which is already a queued job carrying the batch and
 * the daily-budget middleware. Making this queued too would put a second job
 * behind the first, outside the batch, and outside the throttle that exists to
 * stop bulk mail eating the allowance transactional mail needs.
 */
class WeeklyNewsletter extends Notification
{
    /**
     * `$recap` is this reader's week written in their own register, or NULL —
     * which is not an error state but the DEFAULT one. The template answers
     * null with the deterministic copy that shipped long before any of this
     * existed, so a reader whose recap failed still gets last month's email.
     * See {@see RecapWriter}.
     *
     * @param  array{teams: list<array<string, mixed>>, since: mixed, has_results: bool}  $digest
     * @param  array{headline: string, body: list<string>}|null  $recap
     */
    public function __construct(public array $digest, public ?array $recap = null) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $unsubscribe = $this->unsubscribeUrl($notifiable);

        return (new MailMessage)
            /*
             * `for:` is passed to every Voice call here, and omitting it is the
             * bug this class is most likely to grow. line() falls back to
             * auth()->user(), which is NULL inside a queued job — so a missing
             * argument does not error, it silently renders the PG-13 line to
             * everybody regardless of what they chose.
             */
            ->subject(Voice::line('mail.newsletter.subject', for: $notifiable))
            ->markdown('mail.newsletter', [
                'user' => $notifiable,
                'digest' => $this->digest,
                'recap' => $this->recap,
                'unsubscribeUrl' => $unsubscribe,
            ])
            /*
             * RFC 8058. These two headers are what make Gmail and Apple Mail
             * render their OWN unsubscribe control at the top of the message —
             * which is both better for the reader than hunting the footer, and
             * the single largest thing under our control that keeps this out of
             * a spam folder. The POST variant is why the route answers POST and
             * is exempt from CSRF.
             */
            ->withSymfonyMessage(function ($message) use ($unsubscribe): void {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$unsubscribe}>");
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                $message->getHeaders()->addTextHeader('List-Id', Brand::name().' weekly <weekly.'.parse_url(config('app.url'), PHP_URL_HOST).'>');
            });
    }

    /**
     * Permanent rather than temporary: an email sits in an inbox for months and
     * a reader who finds it in March must still be able to make it stop. An
     * expiring unsubscribe is an unsubscribe that eventually becomes a spam
     * report.
     */
    private function unsubscribeUrl(object $notifiable): string
    {
        return URL::signedRoute('newsletter.unsubscribe', ['user' => $notifiable->getKey()]);
    }
}
