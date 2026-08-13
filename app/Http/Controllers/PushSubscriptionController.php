<?php

namespace App\Http\Controllers;

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

        if ($subscription->wasRecentlyCreated) {
            $request->user()->notify(new PushWelcomeNotification);
        }

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        $request->user()->deletePushSubscription($validated['endpoint']);

        return response()->noContent();
    }
}
