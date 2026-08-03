<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.auth')] class extends Component
{
    public bool $sent = false;

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

<div class="flex flex-col gap-6 text-center">
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
