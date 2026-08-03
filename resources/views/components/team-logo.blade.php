@props([
    'team',
    'size' => 'md',
])

@php
    /*
     * ESPN logos are all 500x500 with wildly inconsistent content. A square mark
     * like Georgia's "G" fills the canvas; a wide one like Texas's longhorn
     * occupies only the middle ~40% band. Rendered in the same small square box
     * the wide marks shrink to a few pixels and read as missing entirely.
     *
     * So these boxes are deliberately larger than a naive icon size, and the
     * image is allowed to fill the box's width — a wide mark then gets the full
     * width and simply sits shorter, which is legible, rather than being scaled
     * down to fit a height it never needed.
     */
    $box = match ($size) {
        'xs' => 'size-5',
        'sm' => 'size-6',
        'md' => 'size-8',
        'lg' => 'size-10',
        'xl' => 'size-14',
        '2xl' => 'size-20',
        default => $size,
    };
@endphp

@if ($team?->logo)
    {{-- Two images rather than one: dark mode gets ESPN's dark variant, which
         is synced but was previously never used, so light-on-light marks were
         invisible against a dark surface. --}}
    <img
        src="{{ $team->logo }}"
        alt=""
        loading="lazy"
        {{ $attributes->class([$box, 'shrink-0 object-contain', 'dark:hidden' => (bool) $team->logo_dark]) }}
    >

    @if ($team->logo_dark)
        <img
            src="{{ $team->logo_dark }}"
            alt=""
            loading="lazy"
            {{ $attributes->class([$box, 'hidden shrink-0 object-contain dark:block']) }}
        >
    @endif
@else
    <div {{ $attributes->class([$box, 'shrink-0 rounded-full bg-zinc-100 dark:bg-zinc-800']) }}></div>
@endif
