<?php

namespace App\Notifications;

use App\Models\Game;
use App\Support\Brand;
use App\Support\Voice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Your team kicks off soon" — the first real push, and the whole re-entry
 * loop in one notification: the tap deep-links to the game screen INSIDE
 * the installed app (sw.js notificationclick), with the boot splash playing
 * over the cold start.
 *
 * The title is the matchup, factually — the fact never jokes — and the
 * voice rides the body, personalized to the reader's own followed team.
 * Everything is flattened to scalars at construction so the queued payload
 * never serializes a model.
 */
class KickoffAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private string $matchup;

    private string $url;

    private string $tag;

    public function __construct(Game $game, private string $team)
    {
        $this->matchup = $game->name;
        $this->url = route('game', $game);
        $this->tag = "kickoff-{$game->id}";
    }

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->matchup)
            ->body(Voice::line('push.kickoff.body', ['team' => $this->team], for: $notifiable))
            ->icon(Brand::asset('icon-192'))
            ->badge(Brand::asset('icon-192'))
            ->tag($this->tag)
            ->data(['url' => $this->url]);
    }
}
