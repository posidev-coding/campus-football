<?php

namespace App\Listeners;

use App\Actions\RecordUxEvent;
use App\Enums\UxSignal;
use Illuminate\Auth\Events\Registered;

/**
 * Count every account creation into the funnel, wherever it was made.
 *
 * On the EVENT rather than in a screen, and deliberately: the signal used to
 * be emitted by the overlay wizard alone, so `onboarding_registered` was
 * really measuring wizard completions — every account made through /register
 * or the header's Sign up button was invisible to the funnel, and the step
 * agreed perfectly with the two wizard steps that follow it because those are
 * the only people it could see. Two components emitting one funnel signal is
 * how that drifted; a listener on Registered counts a registration by
 * construction, and a third registration path added later is counted without
 * anybody remembering to.
 *
 * Sibling of GrantVerificationReward, which rides Verified for the same
 * reason: an auth lifecycle fact belongs to the lifecycle event, not to
 * whichever screen happened to trigger it.
 *
 * Synchronous, like the reward, and safe to be: RecordUxEvent is one Redis
 * round trip that swallows every failure. `handle()` rather than
 * `handleOnce()` — one Registered IS one registration, so there is nothing to
 * dedupe, and the dedupe key would put a user id into a pipeline that has
 * none anywhere else.
 */
class CountRegistration
{
    public function __construct(protected RecordUxEvent $events) {}

    public function handle(Registered $event): void
    {
        $this->events->handle(UxSignal::OnboardingRegistered);
    }
}
