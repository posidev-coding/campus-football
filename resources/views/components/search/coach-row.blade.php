@props(['coach'])

@php
    $team = $coach->latestSeason?->team;

    $subtext = collect([
        'Head Coach',
        $team?->placeName(),
        $coach->careerRecord(),
    ])->filter()->implode(' · ');

    $hometown = $coach->hometown();
@endphp

<x-search.row :href="route('coach', $coach)" :attributes="$attributes">
    {{-- There is no reliable coach headshot source — players/full/{id}.png
         resolves for maybe a third of them, incidentally, where a coach's id
         matches their old player id. The team logo is the honest fallback. --}}
    @if ($coach->headshot_url)
        <img src="{{ $coach->headshot_url }}" alt="" loading="lazy" class="size-8 shrink-0 rounded-full bg-zinc-100 object-cover dark:bg-zinc-800">
    @elseif ($team)
        <x-team-logo :team="$team" size="sm" />
    @else
        <flux:icon name="user" variant="mini" class="size-8 shrink-0 rounded-full bg-zinc-100 p-1.5 text-zinc-400 dark:bg-zinc-800" />
    @endif

    <span class="min-w-0 flex-1">
        <span class="block truncate text-sm">{{ $coach->display_name }}</span>

        @if ($subtext !== '')
            <span class="block truncate text-micro text-zinc-500">{{ $subtext }}</span>
        @endif

        @if ($hometown)
            <span class="block truncate text-micro text-zinc-400">{{ $hometown }}</span>
        @endif
    </span>
</x-search.row>
