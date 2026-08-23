<?php

namespace App\Notifications;

use App\Actions\GrantWalletEntry;
use App\Support\Brand;
use App\Support\Voice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "The week is official" — to somebody who actually played it.
 *
 * The payoff moment, and the one message in the loop worth arriving even
 * when nothing went right: a loss with a placement is a result, and a
 * result is what people came for.
 *
 * Carries the nemesis and the Bear as LINES rather than as their own sends.
 * A weekly pick'em rivalry is genuinely week to week, so the person one
 * place away is the honest version of it and the settled field already
 * knows who that is — no table, no declaration, no fourth email.
 *
 * Scalars only at construction; `for:` on every line, because this renders
 * inside a queued job where Voice has no authenticated user to fall back on
 * and the failure is silent.
 */
class SlateSettled extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<string, mixed>  $result */
    public function __construct(public readonly array $result) {}

    /** @return list<string|class-string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->pickem_notify_opt_in && $notifiable->email_verified_at !== null) {
            $channels[] = 'mail';
        }

        if ($notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(Voice::line('notify.results.subject', $this->replace(), for: $notifiable).' — '.Brand::name())
            ->greeting('Hey, '.$notifiable->first_name.'.')
            ->line($this->headline($notifiable));

        foreach ($this->asides($notifiable) as $aside) {
            $mail->line($aside);
        }

        return $mail
            ->action('See the week', $this->result['url'])
            ->salutation('— '.Brand::name())
            ->withSymfonyMessage(function ($message) use ($notifiable): void {
                $url = URL::signedRoute('newsletter.unsubscribe', [
                    'user' => $notifiable->getKey(),
                    'list' => 'pickem',
                ]);

                $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$url}>");
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                $message->getHeaders()->addTextHeader('List-Id', Brand::name().' pick\'em <pickem.'.parse_url(config('app.url'), PHP_URL_HOST).'>');
            });
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(Voice::line('notify.results.subject', $this->replace(), for: $notifiable))
            ->body($this->headline($notifiable))
            ->icon(Brand::asset('icon-192'))
            ->badge(Brand::asset('icon-192'))
            ->tag('slate-results-'.($this->result['slate_id'] ?? ''))
            ->data(['url' => $this->result['url']]);
    }

    /**
     * The inbox row. STRUCTURED, never rendered: freezing the copy here
     * would pin the register at send time, so a reader who later moves to
     * PG would still be shown the PG-13 line in their own inbox.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'slate-results',
            'key' => $this->bodyKey(),
            'replace' => $this->replace(),
            'url' => $this->result['url'],
        ];
    }

    private function headline(object $notifiable): string
    {
        return Voice::line($this->bodyKey(), $this->replace(), for: $notifiable);
    }

    private function bodyKey(): string
    {
        return $this->result['won'] ? 'notify.results.won.body' : 'notify.results.lost.body';
    }

    /**
     * The lines under the headline: a shared win, the practice-week
     * qualifier, the nemesis, the Bear. Each render-guarded, so a slate
     * without a Bear simply says less rather than leaving a hole.
     *
     * @return list<string>
     */
    private function asides(object $notifiable): array
    {
        $lines = [];

        if ($this->result['won'] && filled($this->result['others'])) {
            $lines[] = Voice::line('notify.results.won.shared', $this->replace(), for: $notifiable);
        }

        if (filled($this->result['rival'])) {
            $lines[] = Voice::line(
                $this->result['won'] ? 'notify.results.nemesis.won' : 'notify.results.nemesis',
                $this->replace(),
                for: $notifiable,
            );
        }

        if ($this->result['beat_bear'] !== null) {
            $lines[] = Voice::line(
                $this->result['beat_bear'] ? 'notify.results.bear.beat' : 'notify.results.bear.lost',
                ['margin' => $this->result['bear_margin']],
                for: $notifiable,
            );
        }

        /*
         * The practice qualifier goes LAST and is not optional. Without it
         * the Week 0 rehearsal emails people "You won Week 0" about a week
         * `Slate::counts()` says the season does not remember.
         */
        if ($this->result['exhibition']) {
            $lines[] = Voice::line('notify.results.exhibition', for: $notifiable);
        }

        return array_values(array_filter($lines));
    }

    /** @return array<string, string> */
    private function replace(): array
    {
        return [
            'week' => (string) $this->result['week'],
            'group' => (string) $this->result['group'],
            'points' => (string) $this->result['points'],
            'place' => (string) $this->result['place'],
            'field' => (string) $this->result['field'],
            'others' => (string) $this->result['others'],
            'winner' => (string) $this->result['winner'],
            'rival' => (string) $this->result['rival'],
            'margin' => (string) $this->result['margin'],
            'xp' => (string) GrantWalletEntry::PICKEM_WIN_XP,
        ];
    }
}
