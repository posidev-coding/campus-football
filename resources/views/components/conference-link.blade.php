@props([
    'conference',
    'year' => null,
    'label' => 'name',
    'logo' => false,
])

@php
    /*
     * Conferences have no page of their own yet, so the appropriate destination
     * is the standings filtered to that conference and season — which is what a
     * conference name is really asking for.
     */
    $text = match ($label) {
        'abbr' => $conference?->abbreviation ?? $conference?->short_name ?? $conference?->name,
        'short' => $conference?->short_name ?? $conference?->name,
        default => $conference?->name,
    };
@endphp

@if ($conference)
    <a
        href="{{ route('standings', ['conference' => $conference->id, 'year' => $year ?? config('cfb.season')]) }}"
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
