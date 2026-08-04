@props([
    'conference',
    'year' => null,
    'label' => 'name',
    'logo' => false,
])

@php
    /*
     * `abbreviation` is NOT an abbreviation. Despite the name it holds ESPN's
     * URL slug — `acc`, `big10`, `usa`, `midam`, `mwest`, `belt` — so rendering
     * it puts lowercase slugs in front of the reader. `short_name` is the real
     * display form: ACC, Big Ten, CUSA, MAC, Mountain West, Sun Belt.
     */
    $text = match ($label) {
        'abbr', 'short' => $conference?->short_name ?? $conference?->name,
        default => $conference?->name,
    };
@endphp

@if ($conference)
    <a
        href="{{ route('conference', ['conference' => $conference->id] + ($year ? ['year' => $year] : [])) }}"
        wire:navigate
        {{ $attributes->class(['group inline-flex min-w-0 items-center gap-1.5']) }}
    >
        @if ($logo && $conference->logo)
            <img src="{{ $conference->logo }}" alt="" loading="lazy" class="size-4 shrink-0 object-contain">
        @endif
        <span class="truncate group-hover:underline">{{ $text }}</span>
    </a>
@else
    <span {{ $attributes->class(['text-zinc-500']) }}>Independent</span>
@endif
