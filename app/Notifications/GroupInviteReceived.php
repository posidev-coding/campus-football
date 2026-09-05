<?php

namespace App\Notifications;

use App\Models\Group;
use App\Models\User;
use App\Support\Brand;
use App\Support\Voice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "@taylor wants you in Tuesday Night Lights" — a direct invite, landing in
 * the inbox that already exists.
 *
 * NEVER MAILS, for SlateMissed's reason exactly: this is unsolicited post
 * about a group the reader did not ask to be in. An inbox row and a push are
 * dismissible and cost nothing; an email from us about a stranger's group is
 * the thing that gets a domain marked as spam.
 *
 * `data.url` is the ordinary `/join/{CODE}?by=handle` link, so tapping the
 * row lands on the invite screen every other invite lands on — the sender
 * previewed nothing different from what the recipient reads, and there is
 * exactly one place that seats anybody.
 *
 * The stored payload is a Voice KEY plus replacements, never rendered copy
 * (the inbox's contract): the line resolves in the READER's register at
 * render, so somebody who moves to PG stops being shouted at retroactively.
 * `toWebPush` is the one place that renders, and it passes `for:` — a queued
 * job that forgets it renders PG-13 to everybody.
 */
class GroupInviteReceived extends Notification implements ShouldQueue
{
    public function __construct(
        public readonly Group $group,
        public readonly User $inviter,
    ) {}

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
            ->title(Voice::line('notify.invite.subject', $this->replace(), for: $notifiable))
            ->body(Voice::line($this->key(), $this->replace(), for: $notifiable))
            ->icon(Brand::asset('icon-192'))
            ->badge(Brand::asset('icon-192'))
            ->tag('group-invite-'.$this->group->id)
            ->data(['url' => $this->url()]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'group-invite',
            'key' => $this->key(),
            'replace' => $this->replace(),
            'url' => $this->url(),
        ];
    }

    /**
     * Two lines, because a handle is the only name this may carry and an
     * unclaimed one is missing data, not a blank to fill. The anonymous
     * line names the GROUP instead — never a substituted stand-in for a
     * person.
     */
    private function key(): string
    {
        return $this->inviter->handle === null
            ? 'notify.invite.body.anon'
            : 'notify.invite.body';
    }

    /** @return array<string, string> */
    private function replace(): array
    {
        return array_filter([
            'group' => $this->group->name,
            'inviter' => $this->inviter->handle === null ? null : '@'.$this->inviter->handle,
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * The one invite link, credited where there is a handle to credit it to.
     */
    private function url(): string
    {
        return route('pickem.join', array_filter([
            'code' => $this->group->code,
            'by' => $this->inviter->handle,
        ]));
    }
}
