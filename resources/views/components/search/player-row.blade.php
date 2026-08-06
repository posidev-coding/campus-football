@props(['athlete', 'season' => null, 'logo' => false])

@php
    /*
     * `season` is optional and defaults to the athlete's most recent one, which
     * is what search wants: it has no year in mind. A YEAR-SCOPED caller must
     * pass the row it is showing, or a 2025 list prints every player's 2026
     * team. Passing it also saves a lazy load per row — and lazy loading is
     * disabled app-wide, so that is a 500, not an N+1.
     */
    $season ??= $athlete->latestSeason;

    $subtext = collect([
        $season?->jersey ? '#'.$season->jersey : null,
        $season?->position?->abbreviation,
        $season?->experience_class,
        $season?->team?->placeName(),
    ])->filter()->implode(' · ');

    $hometown = $athlete->hometown();
@endphp

<x-search.row :href="route('player', $athlete)" :attributes="$attributes">
    @if ($athlete->headshot_url)
        <img src="{{ $athlete->headshot_url }}" alt="" loading="lazy" class="size-8 shrink-0 rounded-full bg-zinc-100 object-cover dark:bg-zinc-800">
    @else
        <flux:icon name="user" variant="mini" class="size-8 shrink-0 rounded-full bg-zinc-100 p-1.5 text-zinc-400 dark:bg-zinc-800" />
    @endif

    <span class="min-w-0 flex-1">
        <span class="block truncate text-sm">{{ $athlete->display_name }}</span>

        @if ($subtext !== '')
            <span class="block truncate text-micro text-zinc-500">{{ $subtext }}</span>
        @endif

        {{-- Its own line, not another "·" segment: a one-line subtext
             truncates on a 390px row and the hometown is the first thing
             lost. About half of athletes have one at all, so the row must
             read right without it. --}}
        @if ($hometown)
            <span class="block truncate text-micro text-zinc-400">{{ $hometown }}</span>
        @endif
    </span>

    {{-- Opt-in, and off for search: those results are a mixed list where a team
         row already carries its own mark, so a second one on every player row
         is noise. On a screen that is nothing BUT players it is the fastest way
         to read which team each one belongs to.

         Guarded on the team rather than left to the component, which draws a
         grey puck for a missing logo — honest as a placeholder inside a team
         row, just clutter out here. --}}
    @if ($logo && $season?->team)
        <x-team-logo :team="$season->team" size="sm" />
    @endif
</x-search.row>
