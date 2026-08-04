@props([
    'team',
    'size' => 'sm',
    'label' => 'name',
    'rank' => null,
    'record' => null,
    'muted' => false,
    'logo' => true,
])

@php
    /*
     * The single way a team is rendered anywhere in the app. Every team name,
     * abbreviation and logo is a link to that team — there is no reason for a
     * user to see a team and not be able to reach it.
     *
     * Falls back to plain text when the team is missing (an FCS opponent we do
     * not carry, a TBD bowl slot) rather than rendering a dead link.
     */
    $text = match ($label) {
        'abbr' => $team?->abbreviation ?? $team?->short_display_name,
        'short' => $team?->short_display_name ?? $team?->display_name,
        // The place without the nickname, shortened when the place itself is
        // long. See Team::placeName().
        'location' => $team?->placeName(),
        'none' => null,
        default => $team?->display_name,
    };

    $textSize = match ($size) {
        'xs' => 'text-micro',
        'sm' => 'text-sm',
        'md' => 'text-base',
        'lg' => 'text-lg font-semibold',
        default => 'text-sm',
    };
@endphp

@if ($team)
    <a
        href="{{ route('team', $team) }}"
        wire:navigate
        {{ $attributes->class([
            'group flex min-w-0 items-center gap-2',
            'opacity-45' => $muted,
        ]) }}
    >
        @if ($logo)
            <x-team-logo :team="$team" :size="$size === 'xs' ? 'xs' : ($size === 'lg' ? 'lg' : 'sm')" />
        @endif

        @if ($rank)
            <span class="tabular shrink-0 text-micro font-semibold text-zinc-500">{{ $rank }}</span>
        @endif

        @if ($text)
            <span class="{{ $textSize }} min-w-0 truncate group-hover:underline">{{ $text }}</span>
        @endif

        @if ($record)
            <span class="tabular shrink-0 text-micro text-zinc-400">{{ $record }}</span>
        @endif

        {{ $slot }}
    </a>
@else
    {{-- An unfilled slot, not an error. Every bowl and playoff game is
         published TBD-vs-TBD months ahead, so this is what most of the
         postseason looks like until December. It keeps the logo's footprint so
         a scheduled fixture reads as the same shape of card as a played one
         rather than as a collapsed row. --}}
    <span {{ $attributes->class(['flex min-w-0 items-center gap-2 text-zinc-500', $textSize]) }}>
        @if ($logo)
            <x-team-logo :team="null" :size="$size === 'xs' ? 'xs' : ($size === 'lg' ? 'lg' : 'sm')" />
        @endif

        <span class="truncate">TBD</span>
    </span>
@endif
