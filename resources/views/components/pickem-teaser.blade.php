{{--
    A designed card that WAS deliberately inert — no link, no button — so the
    app read as a pick'em host from the first screen without promising a
    screen that was not there. The Picks screen exists now, so the card is the
    entry point it always planned to become: the whole card navigates, because
    a card with one destination and a separate "go" affordance is two taps
    drawn as one.
--}}
<a
    href="{{ route('pickem.lobby') }}"
    wire:navigate
    {{ $attributes->class(['block rounded-xl border border-dashed border-zinc-300 px-4 py-3 transition-colors hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:border-zinc-600 dark:hover:bg-zinc-900']) }}
>
    <div class="flex items-center gap-2">
        <flux:icon name="check-badge" variant="mini" class="text-zinc-400" />
        <span class="font-semibold">Pick'em</span>
        <flux:badge size="sm" color="zinc">Coming soon</flux:badge>
    </div>

    <p class="pt-1 text-sm text-zinc-500 dark:text-zinc-400">
        {{ App\Support\Voice::line('home.pickem') }}
    </p>
</a>
