@props([
    'href',
    'icon',
    'label',
    'active' => null,
])

@php
    /*
     * `active` is passed in rather than derived from the URL. A tab represents
     * an AREA, and an area covers many routes — a game page keeps Scores lit,
     * a player page keeps League lit. Comparing request()->url() to the tab's
     * own href would only ever light up on the area's landing screen and would
     * go dark the moment you navigated one level in.
     */
    $active ??= $href !== '#' && request()->url() === $href;
@endphp

<a
    href="{{ $href }}"
    @if ($href !== '#') wire:navigate @endif
    @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'flex flex-col items-center justify-center gap-0.5 px-0.5 text-micro font-medium transition-colors',
        'text-zinc-900 dark:text-zinc-100' => $active,
        'text-zinc-500 dark:text-zinc-500' => ! $active,
    ]) }}
>
    {{-- Solid when current, outline otherwise: at 5-6 tabs the labels get
         small, so the icon carries most of the state. --}}
    <flux:icon :name="$icon" :variant="$active ? 'solid' : 'outline'" class="size-6" />
    <span class="w-full truncate text-center leading-tight">{{ $label }}</span>
</a>
