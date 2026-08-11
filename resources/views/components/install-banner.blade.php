{{--
    The install pitch on Home. Quieter than the onboarding CTA on purpose —
    zinc where that card is blue — because "follow a team" outranks "install
    the shell" the first time anyone sees this page.

    Dismissal is $persist to localStorage: install state is a property of the
    DEVICE, not the account — the same person on a new phone should hear the
    pitch again, and a guest can dismiss it forever without a session to hold
    it. `data-install-only` removes it inside the installed app by stylesheet,
    so it cannot flash before Alpine boots.
--}}
<div
    x-data="{ dismissed: $persist(false).as('cfb.install.dismissed') }"
    x-show="! dismissed"
    x-cloak
    data-install-only
    {{ $attributes->class([
        'rounded-xl bg-zinc-50 p-4 ring-1 ring-zinc-200',
        'dark:bg-zinc-900 dark:ring-zinc-800',
    ]) }}
>
    <div class="flex items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-3">
            <flux:icon.phone class="mt-1 shrink-0 text-zinc-500 dark:text-zinc-400" variant="mini" />

            <div class="flex min-w-0 flex-col gap-1">
                <flux:heading size="lg">{{ App\Support\Voice::line('install.banner.heading') }}</flux:heading>
                <flux:subheading>{{ App\Support\Voice::line('install.banner.body') }}</flux:subheading>
            </div>
        </div>

        <flux:button
            x-on:click="dismissed = true"
            size="xs"
            square
            variant="ghost"
            icon="x-mark"
            class="-mt-1 shrink-0"
            aria-label="Dismiss"
        />
    </div>

    <flux:button :href="route('get-app')" wire:navigate variant="filled" size="sm" class="mt-3 w-full sm:w-auto">
        Show me how
    </flux:button>
</div>
