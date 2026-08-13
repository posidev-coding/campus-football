<?php

use App\Models\User;
use App\Notifications\PushWelcomeNotification;
use Illuminate\Support\Facades\Notification;

/**
 * The subscription endpoints and the two surfaces that drive them. The
 * subscription IS the consent — there is deliberately no push column on
 * users — so these pin the whole contract: a stored subscription, one
 * welcome push per genuinely new endpoint, and a destroy that leaves
 * nothing behind.
 */
describe('storing this device', function () {
    it('stores the subscription and proves the pipe with one welcome push', function () {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('push.store'), [
            'endpoint' => 'https://push.example.test/send/abc123',
            'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-token'],
            'content_encoding' => 'aes128gcm',
        ])->assertNoContent();

        expect($user->pushSubscriptions()->count())->toBe(1);

        Notification::assertSentTo($user, PushWelcomeNotification::class);
    });

    it('re-registers the same endpoint without re-welcoming anybody', function () {
        // Key rotation and Livewire hops re-POST; wasRecentlyCreated is the
        // gate that keeps the welcome a one-time event per device.
        Notification::fake();

        $user = User::factory()->create();

        $payload = [
            'endpoint' => 'https://push.example.test/send/abc123',
            'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-token'],
        ];

        $this->actingAs($user)->postJson(route('push.store'), $payload)->assertNoContent();
        $this->actingAs($user)->postJson(route('push.store'), $payload)->assertNoContent();

        expect($user->pushSubscriptions()->count())->toBe(1);

        Notification::assertSentToTimes($user, PushWelcomeNotification::class, 1);
    });

    it('validates the subscription shape', function () {
        // The app renders validation as redirects everywhere but api/*
        // (bootstrap's shouldRenderJsonWhen), so a bad payload bounces with
        // errors — and, either way, stores nothing.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('push.store'), ['endpoint' => 'https://push.example.test/send/abc123'])
            ->assertRedirect()
            ->assertSessionHasErrors(['keys.p256dh', 'keys.auth']);

        expect($user->pushSubscriptions()->count())->toBe(0);
    });

    it('rejects guests on both endpoints', function () {
        $this->post(route('push.store'))->assertRedirect(route('login'));
        $this->delete(route('push.destroy'))->assertRedirect(route('login'));
    });
});

describe('removing this device', function () {
    it('deletes by endpoint and leaves other devices alone', function () {
        Notification::fake();

        $user = User::factory()->create();
        $user->updatePushSubscription('https://push.example.test/send/phone', 'k1', 't1');
        $user->updatePushSubscription('https://push.example.test/send/laptop', 'k2', 't2');

        $this->actingAs($user)->deleteJson(route('push.destroy'), [
            'endpoint' => 'https://push.example.test/send/phone',
        ])->assertNoContent();

        expect($user->pushSubscriptions()->pluck('endpoint')->all())
            ->toBe(['https://push.example.test/send/laptop']);
    });
});

describe('the permission surfaces', function () {
    it('gives Account the device switch, gesture-gated', function () {
        // The prompt is spent the moment it shows, so the ask lives inside
        // the tap — the markup pins the flow's load-bearing strings.
        $html = $this->actingAs(User::factory()->create())
            ->get(route('account'))
            ->assertOk()
            ->content();

        // Path fragments, not route(): @js() JSON-escapes the URL's slashes
        // in the rendered attribute, so the raw URL never appears verbatim.
        expect($html)->toContain('data-push-control')
            ->and($html)->toContain('push-subscriptions')
            ->and($html)->toContain('cfbPush');
    });

    it('nudges toured members on Home, inside the installed app only', function () {
        $user = User::factory()->create();
        $user->forceFill(['tour_completed_at' => now()])->save();

        $html = $this->actingAs($user)->get(route('home'))->assertOk()->content();

        // data-standalone-only is what keeps this row and the browser-only
        // install banner disjoint in the same slot; the dismissal is
        // per-user-per-device localStorage, the install banner's philosophy.
        expect($html)->toContain('data-push-banner')
            ->and($html)->toContain('data-standalone-only')
            ->and($html)->toContain('cfb.push.dismissed.')
            ->and($html)->toContain('Turn on');
    });

    it('waits for the tour like the install pitch does, and skips guests', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-push-banner');

        $this->get(route('home'))->assertOk()->assertDontSee('data-push-banner');
    });
});
