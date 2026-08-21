{{--
    A MODE AS A DOOR — the first-run lobby's pitch tile: the mode's mark,
    name and one-line rules on its own colors, walking straight into the
    creation wizard. The blurb is the enum's, so the pitch here, the mode
    cards and the room cards can never tell the mode three ways.
--}}
@props([
    /** @var App\Enums\ContestMode */
    'mode',
])

@php $palette = $mode->palette(); @endphp

<a
    href="{{ route('pickem.create') }}"
    wire:navigate
    {{ $attributes->class(['flex items-center gap-3 rounded-xl border p-4 transition-colors hover:brightness-95 dark:hover:brightness-110', $palette['tile']]) }}
>
    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-white/60 shadow-sm dark:bg-white/10">
        <flux:icon :name="$mode->icon()" variant="mini" class="{{ $palette['icon'] }}" />
    </span>

    <span class="min-w-0 flex-1">
        <span class="block font-bold leading-tight">{{ $mode->label() }}</span>
        <span class="block pt-0.5 text-sm {{ $palette['body'] }}">{{ $mode->blurb() }}</span>
    </span>

    <flux:icon name="chevron-right" variant="micro" class="shrink-0 opacity-50" />
</a>
