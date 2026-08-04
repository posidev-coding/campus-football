{{--
    A designed card, deliberately INERT: no link, no button, nothing to tap.
    It exists so the app reads as a pick'em host from the first screen without
    promising a screen that is not there yet. When Pick'em ships this becomes
    its entry point and takes the freed bottom-nav slot.
--}}
<div {{ $attributes->class(['rounded-xl border border-dashed border-zinc-300 px-4 py-3 dark:border-zinc-700']) }}>
    <div class="flex items-center gap-2">
        <flux:icon name="check-badge" variant="mini" class="text-zinc-400" />
        <span class="font-semibold">Pick'em</span>
        <flux:badge size="sm" color="zinc">Coming soon</flux:badge>
    </div>

    <p class="pt-1 text-sm text-zinc-500 dark:text-zinc-400">
        {{ App\Support\Voice::line('home.pickem') }}
    </p>
</div>
