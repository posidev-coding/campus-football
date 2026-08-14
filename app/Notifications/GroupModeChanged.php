<?php

namespace App\Notifications;

use App\Enums\ContestMode;
use App\Models\Group;
use App\Support\Brand;
use App\Support\Voice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Your group changed its game" — the announcement half of the one mode
 * pivot a season allows. Sent to every member EXCEPT the commissioner who
 * pulled the lever; a rule change the group discovers from the standings
 * is a betrayal, which is why the note is the action's side effect and
 * not a checkbox.
 *
 * The title states the fact plainly (the fact never jokes); the voice
 * rides the body. Scalars only at construction — a queued payload never
 * serializes a model — and `for:` is explicit everywhere because inside a
 * queued job there is no authenticated user for Voice to fall back to.
 */
class GroupModeChanged extends Notification implements ShouldQueue
{
    use Queueable;

    private string $group;

    private string $mode;

    private string $url;

    private string $tag;

    public function __construct(Group $group, ContestMode $mode)
    {
        $this->group = $group->name;
        $this->mode = $mode->label();
        $this->url = route('pickem.group', $group);
        $this->tag = "mode-changed-{$group->id}";
    }

    /** @return list<string|class-string> */
    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        // The subscription IS the consent — a push_subscriptions row can
        // only exist through a grant on a device, so its presence is the
        // whole send gate.
        if ($notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $replace = ['group' => $this->group, 'mode' => $this->mode];

        return (new MailMessage)
            ->subject(Voice::line('notify.mode_changed.subject', $replace, for: $notifiable).' — '.Brand::name())
            ->greeting('Hey, '.$notifiable->first_name.'.')
            ->line(Voice::line('notify.mode_changed.body', $replace, for: $notifiable))
            ->action('Open the group', $this->url)
            ->salutation('— '.Brand::name());
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("{$this->group} plays {$this->mode} now")
            ->body(Voice::line('notify.mode_changed.body', ['group' => $this->group, 'mode' => $this->mode], for: $notifiable))
            ->icon(Brand::asset('icon-192'))
            ->badge(Brand::asset('icon-192'))
            ->tag($this->tag)
            ->data(['url' => $this->url]);
    }
}
