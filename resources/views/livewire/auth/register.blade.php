<?php

use App\Enums\ContentRating;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.auth')] class extends Component
{
    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Pre-selected, not blank. This is a preference with a sensible middle, and
     * an unset radio group would make it feel like a required decision the user
     * has to research before they can sign up.
     */
    public string $content_rating = 'pg13';

    public function register(): void
    {
        /*
         * No handle here anymore: nothing consumes it until Pick'em and chat
         * exist, so asking was a signup toll. Claiming lives on Account.
         */
        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
            'content_rating' => ['required', Rule::enum(ContentRating::class)],
        ]);

        $validated['password'] = Hash::make($validated['password']);

        event(new Registered($user = User::create($validated)));

        Auth::login($user);

        /*
         * The default lands on Home with the team picker OPEN — the same
         * session-flash hand-off the overlay wizard makes (never a query
         * param: an install captures the tab URL, and a flag in the URL
         * reopened the picker on every launch of the installed app).
         * Without the hand-off, everyone arriving from the header's Sign up
         * button finished with zero teams and, because the tour needs
         * something to point at, never saw the tour either. An intended URL
         * still wins — the flash dies unread on that landing.
         */
        session()->flash('onboarding.moment', true);

        $this->redirectIntended(default: route('home', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        title="Get in the game"
        description="Create an account and start making picks."
    />

    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="register" class="flex flex-col gap-6">
        <div class="flex gap-3">
            <flux:input
                wire:model="first_name"
                label="First name"
                type="text"
                name="first_name"
                required
                autofocus
                autocomplete="given-name"
                class="flex-1"
            />

            <flux:input
                wire:model="last_name"
                label="Last name"
                type="text"
                name="last_name"
                required
                autocomplete="family-name"
                class="flex-1"
            />
        </div>

        <flux:input
            wire:model="email"
            label="Email address"
            type="email"
            name="email"
            required
            autocomplete="email"
            placeholder="you@example.com"
        />

        <flux:input
            wire:model="password"
            label="Password"
            type="password"
            name="password"
            required
            autocomplete="new-password"
            viewable
        />

        <flux:input
            wire:model="password_confirmation"
            label="Confirm password"
            type="password"
            name="password_confirmation"
            required
            autocomplete="new-password"
            viewable
        />

        {{-- Asked at registration rather than buried in settings, because it
             changes the voice of the product from the first screen — and
             because a person who would have wanted PG should never have to see
             the alternative first to discover the setting exists. --}}
        <flux:radio.group
            wire:model="content_rating"
            label="Trash talk"
            description="We roast your picks, your team and your record — never you. This sets how hard."
            variant="cards"
            class="flex-col"
        >
            @foreach (ContentRating::cases() as $rating)
                {{-- Three parts, in the order they are read: the rating is the
                     shorthand people scan for, the sub-label says what it means
                     here, and the description says what it actually changes. --}}
                {{-- `label` AND the slot: the cards variant nests its
                     description inside an `if ($label)` branch, so passing only
                     a slot silently drops the description. The slot still wins
                     for what is displayed. --}}
                <flux:radio
                    :value="$rating->value"
                    :label="$rating->label()"
                    :description="$rating->description()"
                >
                    <span class="flex items-baseline gap-2">
                        <span class="font-medium">{{ $rating->label() }}</span>
                        <span class="text-sm text-zinc-500">{{ $rating->subLabel() }}</span>
                    </span>
                </flux:radio>
            @endforeach
        </flux:radio.group>

        <flux:button variant="primary" type="submit" class="w-full">
            Create account
        </flux:button>
    </form>

    <div class="text-center text-sm text-zinc-600 dark:text-zinc-400">
        Already have an account?
        <flux:link :href="route('login')" wire:navigate>Log in</flux:link>
    </div>
</div>
