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

    /*
     * A caller may phrase the link itself — the team hero says "6th in SEC"
     * rather than the bare conference name. Whitespace-only slots (an @if
     * that rendered nothing) fall back to the name.
     *
     * Livewire brackets every @if inside a component with literal
     * `<!--[if BLOCK]><![endif]-->` markers, and they arrive as part of the
     * slot STRING — echoing it through Blade's escaping then prints them as
     * visible text. Strip them before deciding anything.
     */
    $custom = trim(preg_replace('/<!--\[if (?:END)?BLOCK\]><!\[endif\]-->/', '', (string) ($slot ?? '')));
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
        <span class="truncate group-hover:underline">{{ $custom !== '' ? $custom : $text }}</span>
    </a>
@else
    <span {{ $attributes->class(['text-zinc-500']) }}>Independent</span>
@endif
