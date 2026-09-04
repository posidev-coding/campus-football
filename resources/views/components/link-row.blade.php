{{--
    A dashed DOOR: the row that promises a place rather than showing
    content — dashed border is the house grammar for "not yet / elsewhere",
    the chevron says it travels. The slot renders under the title, so a
    caller can stack its own sub-lines (a count, a zinger) without this
    shell caring how many.

    With an `href` it is an address. Without one it is a BUTTON in the same
    clothes, for a door that opens something on this screen (the feedback
    sheet) rather than going somewhere — a look-alike would be a second
    definition of the same row, one bump away from drifting.
--}}
@props(['href' => null, 'title', 'navigate' => true])

@php
    $classes = 'flex w-full items-center justify-between gap-3 rounded-xl border border-dashed border-zinc-300 px-4 py-3 text-start transition-colors hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:border-zinc-600 dark:hover:bg-zinc-900';
@endphp

@if ($href !== null)
    <a
        href="{{ $href }}"
        @if ($navigate) wire:navigate @endif
        {{ $attributes->class([$classes]) }}
    >
        <span class="min-w-0">
            <span class="block truncate font-semibold leading-tight">{{ $title }}</span>
            {{ $slot }}
        </span>
        <flux:icon name="chevron-right" variant="micro" class="shrink-0 text-zinc-400" />
    </a>
@else
    <button type="button" {{ $attributes->class([$classes]) }}>
        <span class="min-w-0">
            <span class="block truncate font-semibold leading-tight">{{ $title }}</span>
            {{ $slot }}
        </span>
        <flux:icon name="chevron-right" variant="micro" class="shrink-0 text-zinc-400" />
    </button>
@endif
