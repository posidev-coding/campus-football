{{--
    THE NEXT-UP SLOT — Home's one prioritized nudge, resolved by
    PickemPulse's ladder. One card and only ever one: tone tint, icon
    puck, one Voice line carrying the count or the stakes, a PLAIN
    action line under it, a chevron. The whole card is the CTA.

    Tone budget honored: amber = needs you (and this slot is the one
    amber thing on the screen), emerald = live/won, blue = a way in,
    zinc = calm. Dark mode keeps the non-color signals — the icon and
    the action line say what the tint says.
--}}
@props([
    /** @var array{key: string, replace: array<string, string>, tone: string, icon: string, cta: string, href: string} */
    'nudge',
])

@php
    $line = App\Support\Voice::line($nudge['key'], $nudge['replace']);

    $tones = [
        'amber' => 'border-amber-300 bg-amber-50/60 dark:border-amber-700/60 dark:bg-amber-950/30',
        'emerald' => 'border-emerald-300 bg-emerald-50/60 dark:border-emerald-800/60 dark:bg-emerald-950/30',
        'blue' => 'border-blue-200 bg-blue-50/60 dark:border-blue-900 dark:bg-blue-950/30',
        'zinc' => 'border-zinc-200 dark:border-zinc-700',
    ];
@endphp

{{-- Render-guarded: an unwritten register is no slot, never a hole. --}}
@if ($line !== '')
    <a
        href="{{ $nudge['href'] }}"
        wire:navigate
        {{ $attributes->class(['flex items-center gap-3 rounded-xl border px-4 py-3', $tones[$nudge['tone']] ?? $tones['zinc']]) }}
    >
        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/70 shadow-sm dark:bg-white/10">
            <flux:icon :name="$nudge['icon']" variant="mini" class="text-zinc-700 dark:text-zinc-200" />
        </span>

        <span class="min-w-0 flex-1">
            <span class="block text-sm font-medium">{{ $line }}</span>
            {{-- The affordance stays plain in every register. --}}
            <span class="block pt-0.5 text-micro font-semibold text-zinc-600 dark:text-zinc-300">{{ $nudge['cta'] }}</span>
        </span>

        <flux:icon name="chevron-right" variant="micro" class="shrink-0 text-zinc-400" />
    </a>
@endif
