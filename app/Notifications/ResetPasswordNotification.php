<?php

namespace App\Notifications;

use App\Support\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * Replaces the framework's ResetPassword notification for two reasons, and the
 * second is the one that matters.
 *
 * It is BRANDED — the stock one renders Laravel's own template with its own
 * logo, which is a strange thing for a reader to receive from a football app.
 *
 * And it is QUEUED. The framework's version is not `ShouldQueue`, so it sends
 * inside the web request: against `log` that is free and invisible, but behind
 * real SMTP it is a network handshake the user waits through on a form submit.
 * `toMailUsing()` would have restyled the stock notification without moving it
 * off the request, which is why this is a class rather than a closure.
 *
 * Nothing about the FLOW changes. The screen still reports the same constant
 * status whether or not the address exists, because that is enumeration
 * defense and it lives in the Livewire component, not here.
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiry = Config::get('auth.passwords.'.Config::get('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Reset your '.Brand::name().' password')
            ->greeting('Hi '.$notifiable->first_name.',')
            ->line('Somebody asked to reset the password on this account.')
            ->action('Choose a new password', $this->resetUrl($notifiable))
            ->line("This link stops working in {$expiry} minutes.")
            /*
             * The reassurance is the point of this line, not the instruction.
             * A reset email lands in the inbox of people who did NOT request
             * one, and the only thing they need to be told is that ignoring it
             * is safe.
             */
            ->line('If this was not you, nothing has changed and you can ignore this.')
            ->salutation('— '.Brand::name());
    }

    private function resetUrl(object $notifiable): string
    {
        return url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], absolute: false));
    }
}
