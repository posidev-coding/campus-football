<?php

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Where a verify click LANDS — the branch VerifyEmailController takes on
 * User::hasInstalled(). An installed reader's tab has one job (bounce them
 * back to the app); everyone else lands on Home wearing the one-load
 * verify.moment flash. The ?verified=1 param this replaced was state in a
 * URL that nothing read — and a home-screen install captures the tab URL,
 * which is exactly why the flash idiom exists.
 */
function signedVerifyUrl(User $user): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);
}

describe('the landing branch', function () {
    it('sends an installed reader to the off-ramp, ignoring intended()', function () {
        $user = User::factory()->unverified()->installed()->create();

        // A stale deep link must not hijack the off-ramp — the tab's job is
        // to end, and the intended URL survives for its next consumer.
        $this->withSession(['url.intended' => route('account')])
            ->actingAs($user)
            ->get(signedVerifyUrl($user))
            ->assertRedirect(route('verification.done'))
            ->assertSessionMissing('verify.moment');
    });

    it('sends a browser-only reader to Home with the one-load flash', function () {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(signedVerifyUrl($user))
            ->assertRedirect(route('home'))
            ->assertSessionHas('verify.moment');
    });

    it('lets an intended URL win on the browser branch, the register() precedent', function () {
        $user = User::factory()->unverified()->create();

        // The flash dies unread on that landing — accepted, same wording as
        // the onboarding hand-off.
        $this->withSession(['url.intended' => route('account')])
            ->actingAs($user)
            ->get(signedVerifyUrl($user))
            ->assertRedirect(route('account'));
    });

    it('treats a stale re-clicked link exactly like a fresh one', function () {
        $user = User::factory()->installed()->create(); // already verified

        $this->actingAs($user)
            ->get(signedVerifyUrl($user))
            ->assertRedirect(route('verification.done'));

        // The keyed grant pays nothing twice.
        expect($user->fresh()->walletEntries()->count())->toBeLessThanOrEqual(1);
    });
});

describe('the off-ramp screen', function () {
    it('renders both stylesheet-branched bodies, coaching and in-app alike', function () {
        /*
         * The browser body coaches back to the icon; the in-app body exists
         * because Android link capturing can land this same screen INSIDE
         * the PWA. The split is CSS (data-install-only vs the inverse
         * marker), never JS, so neither can flash — a feature test can only
         * hold the markers and the copy.
         */
        $this->actingAs(User::factory()->installed()->create())
            ->get(route('verification.done'))
            ->assertOk()
            ->assertSee('data-install-only', escape: false)
            ->assertSee('data-standalone-only', escape: false)
            ->assertSee('Continue in browser')
            ->assertSee('Go to Home')
            ->assertSee(route('home'));
    });

    it('keeps the auth layout escape hatches', function () {
        // The depth-aware Back — this screen is still a screen someone can
        // change their mind on, installed app or not.
        $this->actingAs(User::factory()->installed()->create())
            ->get(route('verification.done'))
            ->assertOk()
            ->assertSee('window.cfbAppDepth > 1', false)
            ->assertSee('>Back</button>', false);
    });

    it('never claims a verification that has not happened', function () {
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('verification.done'))
            ->assertRedirect(route('verification.notice'));
    });

    it('is gated behind auth like the rest of the flow', function () {
        $this->get(route('verification.done'))->assertRedirect(route('login'));
    });
});
