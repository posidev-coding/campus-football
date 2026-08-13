<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            // A stale or re-clicked link lands exactly like a fresh one —
            // the keyed reward grant already paid nothing twice.
            return $this->landing($request->user());
        }

        if ($request->user()->markEmailAsVerified()) {
            /** @var MustVerifyEmail $user */
            $user = $request->user();

            event(new Verified($user));
        }

        return $this->landing($request->user());
    }

    /**
     * Where the click lands, and it depends on who clicked.
     *
     * An INSTALLED reader (User::hasInstalled()) gets the off-ramp screen:
     * this tab's one job is to bounce them back to the app, so `intended()`
     * is deliberately ignored — a deep URL here would undercut the coaching,
     * and the intended value survives untouched in the session for its next
     * natural consumer.
     *
     * Everyone else lands on Home wearing a one-load `verify.moment` flash —
     * never a query param, because a home-screen install captures the tab
     * URL (the `?verified=1` this replaced was exactly that landmine, and
     * nothing ever read it). Like register(), an intended URL wins over Home
     * and the flash dies unread on that landing — accepted, same precedent.
     */
    private function landing(User $user): RedirectResponse
    {
        if ($user->hasInstalled()) {
            return redirect()->route('verification.done');
        }

        session()->flash('verify.moment', true);

        return redirect()->intended(route('home', absolute: false));
    }
}
