<?php

namespace App\Notifications;

use App\Support\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Notifications\Notification;

/**
 * The one-time code that proves a phone number belongs to the person who typed
 * it.
 *
 * Bypasses `routeNotificationForVonage()` deliberately — that method gates on
 * consent AND on the number already being verified, which this is the thing
 * that establishes. So it addresses the number directly via `routes()`, and it
 * is the only notification in the app allowed to.
 *
 * Carries no ThrottleSms middleware either. This is transactional: somebody is
 * sitting on a form waiting for it, and it is the same reasoning that keeps the
 * daily mail budget off password resets. The rate limit that matters here is
 * per-user attempts, enforced at the form.
 */
class VerifyPhoneNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $code) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['vonage'];
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        /*
         * Plain text, one line, code first.
         *
         * A verification SMS is read from a notification shade without opening
         * it, and iOS and Android both autofill a code they can find early in
         * the message. Anything before it — a greeting, the brand — pushes the
         * digits out of the preview and turns a one-tap flow into a two-app one.
         */
        return (new VonageMessage)
            ->content("{$this->code} is your ".Brand::name().' code. It expires in 10 minutes.');
    }
}
