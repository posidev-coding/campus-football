<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.auth')] class extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    public string $status = '';

    public function sendPasswordResetLink(): void
    {
        $this->validate();

        // Always reports the same thing whether or not the address exists —
        // otherwise this form is an account-enumeration oracle.
        Password::sendResetLink(['email' => $this->email]);

        $this->status = 'If that email is on file, a reset link is on its way.';
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        title="Forgot your password?"
        description="Happens to the best of us. We'll email you a reset link."
    />

    <x-auth-session-status class="text-center" :status="$status" />

    <form wire:submit="sendPasswordResetLink" class="flex flex-col gap-6">
        <flux:input
            wire:model="email"
            label="Email address"
            type="email"
            name="email"
            required
            autofocus
            autocomplete="email"
            placeholder="you@example.com"
        />

        <flux:button variant="primary" type="submit" class="w-full">
            Email password reset link
        </flux:button>
    </form>

    <div class="text-center text-sm text-zinc-600 dark:text-zinc-400">
        Remembered it?
        <flux:link :href="route('login')" wire:navigate>Log in</flux:link>
    </div>
</div>
