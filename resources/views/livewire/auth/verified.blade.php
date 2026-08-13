<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The browser tab's off-ramp after a verify click, shown only to readers the
 * server knows run the installed app (VerifyEmailController branches here on
 * User::hasInstalled()). Its one job is to END this tab well: celebrate the
 * payout, then point at the home screen — which is why it wears the auth
 * layout. Full app chrome would invite staying in the browser, and the auth
 * layout is the established interstitial frame, Back control included.
 *
 * Android link capturing can land this same screen INSIDE the app, where
 * coaching would be nonsense — the two bodies below split that by stylesheet
 * (data-install-only / data-standalone-only), never by JS, so neither can
 * flash before Alpine boots.
 */
new #[Layout('components.layouts.auth')] class extends Component
{
    public function mount(): void
    {
        // This screen must never claim a verification that has not happened.
        if (! auth()->user()->hasVerifiedEmail()) {
            $this->redirect(route('verification.notice'), navigate: true);
        }
    }
}; ?>

<div class="flex flex-col gap-6 text-center">
    <flux:icon.check-badge class="mx-auto size-10 text-emerald-500 dark:text-emerald-400" />

    <x-auth-header
        :title="App\Support\Voice::line('verify.landing.title')"
        :description="App\Support\Voice::line('verify.landing.reward')"
    />

    {{-- The browser tab: coach back to the icon, with a quiet way to stay.
         Hidden inside the installed app by the data-install-only pair. --}}
    <div data-install-only class="flex flex-col gap-4">
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            {{ App\Support\Voice::line('verify.landing.body') }}
        </p>

        {{-- Plain affordance, deliberately subdued: the coaching above is
             the point, this is the escape hatch. --}}
        <flux:link :href="route('home')" wire:navigate class="text-sm">
            Continue in browser
        </flux:link>
    </div>

    {{-- Android link capture landed the click inside the app itself:
         nothing to coach, one tap home. --}}
    <div data-standalone-only class="flex flex-col gap-4">
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            {{ App\Support\Voice::line('verify.landing.body_app') }}
        </p>

        <flux:button :href="route('home')" wire:navigate variant="primary" class="w-full">
            Go to Home
        </flux:button>
    </div>
</div>
