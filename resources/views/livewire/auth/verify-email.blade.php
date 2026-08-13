<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.auth')] class extends Component
{
    public bool $sent = false;

    public function mount(): void
    {
        // The screen exists only while unverified — which is also what
        // licenses the unguarded poll on its root.
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('home', absolute: false), navigate: true);
        }
    }

    /**
     * The poll's landing. The reader is standing at the door they just
     * knocked on — the mail click happens in ANOTHER tab (or another app
     * entirely), and this is how this one finds out. The flash means the
     * Home it lands on celebrates, in the installed app and the browser
     * alike; the redirect is also what ends the poll.
     */
    public function checkVerified(): void
    {
        if (! Auth::user()->hasVerifiedEmail()) {
            return;
        }

        session()->flash('verify.moment', true);

        $this->redirectIntended(default: route('home', absolute: false), navigate: true);
    }

    public function sendVerification(): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('home', absolute: false), navigate: true);

            return;
        }

        Auth::user()->sendEmailVerificationNotification();

        $this->sent = true;
    }

    public function logout(\App\Livewire\Actions\Logout $logout): RedirectResponse
    {
        return $logout();
    }
}; ?>

{{-- 3s, hot like the player screen's in-flight poll: this is the active
     waiting surface, it reads one row of our own database, and the
     checkVerified redirect ends it. Livewire throttles background tabs. --}}
<div class="flex flex-col gap-6 text-center" wire:poll.3s="checkVerified">
    <x-auth-header
        title="Check your email"
        description="We sent a verification link to the address you signed up with. Click it and you're in."
    />

    @if ($sent)
        <flux:callout variant="success" icon="check-circle">
            A fresh link is on its way.
        </flux:callout>
    @endif

    <div class="flex flex-col items-center gap-3">
        <flux:button wire:click="sendVerification" variant="primary" class="w-full">
            Resend verification email
        </flux:button>

        <flux:button wire:click="logout" variant="subtle" class="w-full">
            Log out
        </flux:button>
    </div>
</div>
