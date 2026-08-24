<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.auth')] class extends Component
{
    #[Validate('required|string')]
    public string $password = '';

    public function confirmPassword(): void
    {
        $this->validate();

        if (! Auth::guard('web')->validate([
            'email' => Auth::user()->email,
            'password' => $this->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        session(['auth.password_confirmed_at' => time()]);

        $this->redirectIntended(default: route('home', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        title="Confirm password"
        description="This is a secure area. Confirm your password before continuing."
    />

    <form wire:submit="confirmPassword" class="flex flex-col gap-6">
        <flux:input
            wire:model="password"
            label="Password"
            type="password"
            name="password"
            required
            autofocus
            autocomplete="current-password"
            viewable
        />

        <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="confirmPassword" class="w-full">
            Confirm
        </flux:button>
    </form>
</div>
