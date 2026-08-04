@props(['athlete'])

@php
    $season = $athlete->latestSeason;

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
</x-search.row>
