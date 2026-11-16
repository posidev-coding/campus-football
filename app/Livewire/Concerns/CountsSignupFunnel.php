<?php

namespace App\Livewire\Concerns;

use App\Actions\RecordActivity;
use App\Actions\RecordUxEvent;
use App\Enums\UxSignal;
use App\Models\ActivityEvent;

/**
 * The top of the signup funnel — opened, then reached the credentials —
 * counted for BOTH doors an account is made through, from this one file.
 *
 * There are two: the overlay wizard on Home and the standalone /register
 * form (the header's Sign up, and the invite path). `onboarding_registered`
 * already counts both, because it rides the Registered event. The two steps
 * above it were the wizard's alone, so a week whose only signup came through
 * /register read opened 0, credentials 0, registered 1 — two counters
 * measuring two populations, not a funnel (CFB-110). Counting the form's
 * arrivals too puts every step on one population, and
 * opened >= credentials_reached >= registered holds by construction: nobody
 * registers without having loaded a door that counted them.
 *
 * ONE FILE for both doors, for the reason `RecordsSearches` is one file: a
 * signal emitted from two screens with two slightly different guards stops
 * measuring what it is named after. `UxFunnelTest` sweeps for emitters and
 * names this file and nothing else.
 *
 * Guests only, and once per browser per day on a HASH of the session id —
 * never the id itself, which is the session cookie. The same hash on both
 * doors, so a guest who opens the wizard and then takes the header's Sign up
 * instead is one arrival, not two. The residual is midnight: a door opened
 * at 23:58 and an account made at 00:02 put the two counts on two league
 * days. Over a seven-day total that is one person at the window's edge.
 */
trait CountsSignupFunnel
{
    /**
     * A guest opened a signup door: pressed the wizard's front door, or
     * loaded the /register form.
     *
     * Software is not a guest opening anything. The wizard is behind a
     * JavaScript round trip a crawler never makes, so the form's GET has to
     * refuse what the clickstream already calls automated, or the two doors
     * would count different populations again.
     */
    protected function countSignupOpened(): void
    {
        $this->countSignupStep(UxSignal::OnboardingOpened);
    }

    /**
     * A guest has the email and password form in front of them: the
     * wizard's last pane, or the /register form, which opens on it.
     */
    protected function countCredentialsReached(): void
    {
        $this->countSignupStep(UxSignal::OnboardingCredentialsReached);
    }

    private function countSignupStep(UxSignal $signal): void
    {
        if (auth()->check() || RecordActivity::audience(null, request()) === ActivityEvent::AUTOMATED) {
            return;
        }

        app(RecordUxEvent::class)->handleOnce($signal, hash('sha256', session()->getId()));
    }
}
