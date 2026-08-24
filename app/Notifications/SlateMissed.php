<?php

namespace App\Notifications;

use App\Support\Brand;
use App\Support\Voice;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "It finished without you" — to a member who let the week go by.
 *
 * NEVER MAIL, and that is the whole reason this is a separate class rather
 * than a branch inside SlateSettled. An email about a contest somebody did
 * not enter is unsolicited mail about a thing they did not do; a push and
 * an inbox row are dismissible, cost no budget, and are the strongest
 * re-entry nudge the product has — "@dave won it" is the line that brings
 * somebody back next Saturday.
 *
 * The audience is gated upstream in SlateResults: only members who were in
 * the group BEFORE the card went up, so nobody is told they missed a week
 * they could not have played.
 *
 * NOT `ShouldQueue`, and that is deliberate: it is sent from inside
 * SendSlateResult, which is already a queued job carrying the batch and the
 * daily-budget middleware. Making this queued too would put a second job
 * behind the first, outside the batch and outside the throttle.
 */
class SlateMissed extends Notification
{
    /** @param  array<string, mixed>  $result */
    public function __construct(public readonly array $result) {}

    /** @return list<string|class-string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(Voice::line('notify.results.subject', $this->replace(), for: $notifiable))
            ->body(Voice::line('notify.results.missed.body', $this->replace(), for: $notifiable))
            ->icon(Brand::asset('icon-192'))
            ->badge(Brand::asset('icon-192'))
            ->tag('slate-results-'.($this->result['slate_id'] ?? ''))
            ->data(['url' => $this->result['url']]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'slate-missed',
            'key' => 'notify.results.missed.body',
            'replace' => $this->replace(),
            'url' => $this->result['url'],
        ];
    }

    /** @return array<string, string> */
    private function replace(): array
    {
        return [
            'week' => (string) $this->result['week'],
            'group' => (string) $this->result['group'],
            'winner' => (string) $this->result['winner'],
        ];
    }
}
