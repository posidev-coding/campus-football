@props([
    'athlete',
    'size' => 'sm',
    'headshot' => true,
    'subtitle' => null,
])

@php
    /*
     * Every player reference is a link to that player. Headshots are ESPN CDN
     * PNGs on a transparent background, so they sit on a tinted circle rather
     * than floating on the page background.
     */
    $box = match ($size) {
        'xs' => 'size-7',
        'sm' => 'size-9',
        'md' => 'size-10',
        'lg' => 'size-14',
        default => 'size-9',
    };
@endphp

@if ($athlete)
    <a
        href="{{ route('player', $athlete) }}"
        wire:navigate
        {{ $attributes->class(['group flex min-w-0 items-center gap-2.5']) }}
    >
        @if ($headshot)
            @if ($athlete->headshot_url)
                <img
                    src="{{ $athlete->headshot_url }}"
                    alt=""
                    loading="lazy"
                    class="{{ $box }} shrink-0 rounded-full bg-zinc-100 object-cover object-top dark:bg-zinc-800"
                >
            @else
                <div class="{{ $box }} flex shrink-0 items-center justify-center rounded-full bg-zinc-100 text-micro font-semibold text-zinc-400 dark:bg-zinc-800">
                    {{ str($athlete->display_name)->substr(0, 1) }}
                </div>
            @endif
        @endif

        <span class="flex min-w-0 flex-col">
            <span class="truncate text-sm font-medium group-hover:underline">{{ $athlete->display_name }}</span>
            @if ($subtitle)
                <span class="truncate text-micro text-zinc-500">{{ $subtitle }}</span>
            @endif
        </span>

        {{ $slot }}
    </a>
@else
    <span {{ $attributes->class(['text-sm text-zinc-500']) }}>Unknown</span>
@endif
