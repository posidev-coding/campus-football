<?php

namespace App\Notifications;

use App\Support\Brand;
use App\Support\Voice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The pipe-proving push, sent the moment a device's subscription is first
 * stored. It does double duty: the user asked "did that work?" with the
 * permission grant, and this answers on the only channel where the answer
 * means anything — and it makes the nudge's "first one's already on the
 * way" line literally true.
 *
 * Queued, so `Voice::line(..., for:)` is mandatory — a job has no
 * authenticated user to fall back to, and the silent fallback is PG-13 to
 * everybody.
 */
class PushWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(Voice::line('push.welcome.title', for: $notifiable))
            ->body(Voice::line('push.welcome.body', for: $notifiable))
            ->icon(Brand::asset('icon-192'))
            ->badge(Brand::asset('icon-192'))
            ->tag('welcome')
            ->data(['url' => route('home')]);
    }
}
