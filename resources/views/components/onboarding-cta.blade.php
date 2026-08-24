@props(['guest' => false])

{{--
    The front door. One blue button, and a quiet way to make it go away.

    Guests and signed-in users land in different places — an account flow
    versus the team picker — and the card now makes two different promises to
    match: a guest is sold the whole app (scores, teams, Pick'em on the way),
    a signed-in reader is sold the one thing left to do — put their favorite
    up top. Same card, same button, different pitch.
--}}
{{-- Deliberately louder than the cards around it: a tinted surface and a blue
     ring, so the one thing a new user should press does not read as another
     panel of content. --}}
<div {{ $attributes->class([
    'rounded-xl p-4 ring-1',
    'bg-blue-50 ring-blue-200',
    'dark:bg-blue-950/40 dark:ring-blue-900',
]) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="flex min-w-0 flex-col gap-1">
            @php
                // Guests read the config alone (no user to be admin); the
                // commit-11 mirror, never Feature::active().
                $guestBody = config('cfb.pickem_open') === true || (bool) auth()->user()?->isAdmin()
                    ? 'onboarding.guest.body_live'
                    : 'onboarding.guest.body';
            @endphp
            <flux:heading size="lg">{{ App\Support\Voice::line($guest ? 'onboarding.guest.heading' : 'onboarding.member.heading') }}</flux:heading>
            <flux:subheading>{{ App\Support\Voice::line($guest ? $guestBody : 'onboarding.member.body') }}</flux:subheading>
        </div>

        {{-- Dismissible, and deliberately understated: an X rather than a
             second button competing with the one that matters. --}}
        <flux:button
            wire:click="dismissOnboarding"
            size="xs"
            square
            variant="ghost"
            icon="x-mark"
            class="-mt-1 shrink-0"
            aria-label="Dismiss"
        />
    </div>

    <flux:button
        x-on:click="$dispatch('start-onboarding')"
        variant="primary"
        class="mt-3 w-full sm:w-auto"
    >
        {{ $guest ? 'Get started' : 'Add your team' }}
    </flux:button>
</div>
