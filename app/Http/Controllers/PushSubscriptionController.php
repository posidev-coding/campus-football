<?php

namespace App\Http\Controllers;

use App\Actions\RecordActivity;
use App\Enums\ActivityKind;
use App\Notifications\PushWelcomeNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * This device's push subscription, stored and removed. The subscription IS
 * the consent (see the User model's trait comment): store can only run
 * after the browser granted permission through a real user gesture, and
 * destroy is the Account switch's off position.
 *
 * The welcome push rides `wasRecentlyCreated`, so re-registering the same
 * endpoint (Livewire hop, key rotation replay, a second tab) refreshes keys
 * without re-welcoming anybody.
 */
class PushSubscriptionController
{
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            // Negotiated by the browser (PushManager.supportedContentEncodings);
            // hardcoding the legacy value breaks sends to modern services.
            'content_encoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
        ]);

        $subscription = $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['content_encoding'] ?? null,
        );

        /*
         * Both ride `wasRecentlyCreated`. Re-registering the same endpoint is
         * a Livewire hop, a key rotation replay or a second tab refreshing
         * itself — which is why the welcome push is already gated this way,
         * and a toggle count that moved on each of them would be measuring
         * page loads.
         */
        if ($subscription->wasRecentlyCreated) {
            $request->user()->notify(new PushWelcomeNotification);

            app(RecordActivity::class)->action(ActivityKind::NotificationToggled, $request, 'push_on');
        }

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        $request->user()->deletePushSubscription($validated['endpoint']);

        app(RecordActivity::class)->action(ActivityKind::NotificationToggled, $request, 'push_off');

        return response()->noContent();
    }
}
