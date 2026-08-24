<?php

namespace App\Notifications;

use App\Support\Brand;
use App\Support\PickReminders;
use App\Support\Voice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Your picks are due" — one message per READER, listing every card they
 * still owe picks on.
 *
 * Per reader rather than per slate because nothing caps how many contests a
 * person joins: somebody holding a private group and two lobby rooms would
 * otherwise get three of these on a Friday and three more at last call, from
 * a domain that also carries their password resets.
 *
 * Two waves ride the same class — `remind` a day out, `last_call` ninety
 * minutes out. The wave changes which keys render and nothing else, so the
 * two can never disagree about what is owed.
 *
 * Scalars only at construction: a queued payload never serializes a model.
 * `for:` is explicit on every line because inside a queued job there is no
 * authenticated user for Voice to fall back to, and the failure is SILENT —
 * everybody quietly receives the PG-13 register.
 *
 * Deliberately NOT on the `database` channel. A reminder is only true until
 * kickoff; one sitting in an inbox afterwards is noise about a card that has
 * already locked.
 */
class PickReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{group: string, owed: int, total: int, when: string, url: string}>  $cards
     */
    public function __construct(
        public readonly array $cards,
        public readonly string $wave,
    ) {}

    /** @return list<string|class-string> */
    public function via(object $notifiable): array
    {
        $channels = [];

        // Mail is the floor: push subscriptions can be zero for a reader who
        // has never installed the app, and a reminder nobody receives is not
        // a reminder. Consent and a verified address, the newsletter's gate.
        if ($notifiable->pickem_notify_opt_in && $notifiable->email_verified_at !== null) {
            $channels[] = 'mail';
        }

        // The subscription IS the consent — a push_subscriptions row can only
        // exist through a grant on a device.
        if ($notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        /*
         * SMS is wired and OFF. `User::routeNotificationForVonage()` already
         * refuses anyone who has not verified a number and said yes, so this
         * config switch is the second gate: recurring weekly texts are money
         * and a carrier-complaint surface, and nobody has verified a number
         * yet. Shipping the path inert means the day it flips, the budget
         * middleware and these tests are already there.
         */
        if (config('cfb.pickem_reminder_sms') && $notifiable->canReceiveSms()) {
            $channels[] = 'vonage';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject($notifiable).' — '.Brand::name())
            ->greeting('Hey, '.$notifiable->first_name.'.')
            ->line($this->body($notifiable));

        // One card gets a button straight into it; several get the dashboard,
        // where all of them are already listed in one column.
        return $mail
            ->action(
                count($this->cards) === 1 ? 'Make your picks' : 'See what is open',
                count($this->cards) === 1 ? $this->cards[0]['url'] : route('pickem.home'),
            )
            ->line($this->footnote())
            ->salutation('— '.Brand::name())
            ->withSymfonyMessage(function ($message) use ($notifiable): void {
                $url = $this->unsubscribeUrl($notifiable);

                $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$url}>");
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                $message->getHeaders()->addTextHeader('List-Id', Brand::name().' pick\'em <pickem.'.parse_url(config('app.url'), PHP_URL_HOST).'>');
            });
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $key = $this->wave === PickReminders::WAVE_LAST_CALL
            ? 'notify.last_call.push'
            : 'notify.reminder.push';

        return (new WebPushMessage)
            ->title($this->subject($notifiable))
            ->body(Voice::line($key, $this->replace(), for: $notifiable))
            ->icon(Brand::asset('icon-192'))
            ->badge(Brand::asset('icon-192'))
            /*
             * The tag omits the wave on purpose: the last call REPLACES the
             * day-before nudge on the lock screen rather than stacking a
             * second one beside it. One live reminder, always the current one.
             */
            ->tag('pickem-remind')
            ->data(['url' => count($this->cards) === 1 ? $this->cards[0]['url'] : route('pickem.home')]);
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        return (new VonageMessage)
            ->content(Voice::line('notify.reminder.sms', $this->replace(), for: $notifiable));
    }

    /** The fact, which reads the same in every register. */
    private function subject(object $notifiable): string
    {
        if (count($this->cards) > 1) {
            return Voice::line('notify.reminder.multi', $this->replace(), for: $notifiable);
        }

        return Voice::line(
            $this->wave === PickReminders::WAVE_LAST_CALL ? 'notify.last_call.subject' : 'notify.reminder.subject',
            $this->replace(),
            for: $notifiable,
        );
    }

    private function body(object $notifiable): string
    {
        $key = $this->wave === PickReminders::WAVE_LAST_CALL
            ? 'notify.last_call.body'
            : 'notify.reminder.body';

        return Voice::line($key, $this->replace(), for: $notifiable);
    }

    /** Every other card, named plainly under the voiced line. */
    private function footnote(): string
    {
        if (count($this->cards) === 1) {
            return '';
        }

        return collect($this->cards)
            ->map(fn (array $card) => "{$card['group']}: {$card['owed']} of {$card['total']} left, first kick {$card['when']}")
            ->implode("\n");
    }

    /**
     * The replacement set. When several cards are owed the numbers are the
     * TOTAL across them and `:group` names the busiest — the per-card detail
     * rides the footnote rather than being flattened into one sentence.
     *
     * @return array<string, string>
     */
    private function replace(): array
    {
        $owed = collect($this->cards)->sum('owed');
        $first = collect($this->cards)->sortBy('when')->first();

        return [
            'owed' => (string) $owed,
            'total' => (string) collect($this->cards)->sum('total'),
            'count' => (string) count($this->cards),
            'group' => (string) ($first['group'] ?? ''),
            'when' => (string) ($first['when'] ?? ''),
        ];
    }

    /** Signed, permanent, and naming the pick'em list rather than the digest. */
    private function unsubscribeUrl(object $notifiable): string
    {
        return URL::signedRoute('newsletter.unsubscribe', [
            'user' => $notifiable->getKey(),
            'list' => 'pickem',
        ]);
    }
}
