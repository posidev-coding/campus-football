@props(['game'])

@php
    $home = $game->homeTeam;
    $away = $game->awayTeam;

    // "TBD at TBD" is what ESPN calls an unannounced bowl fixture; the note is
    // the only thing worth reading on one, so it takes the name line.
    $matchup = $away && $home
        ? $away->placeName().' at '.$home->placeName()
        : ($game->note ?: $game->name);

    $status = match (true) {
        $game->isInProgress() => 'Live'.($game->status_detail ? ' · '.$game->status_detail : ''),
        (bool) $game->completed => 'Final · '.$game->away_score.'-'.$game->home_score,
        default => collect([
            $game->kickoffLabel('date'),
            collect($game->broadcasts ?? [])->flatten()->filter()->first(),
        ])->filter()->implode(' · '),
    };

    // The bowl name leads the subtext unless it already took the name line.
    $subtext = collect([
        $away && $home ? $game->note : null,
        $status,
    ])->filter()->implode(' · ');
@endphp

<x-search.row :href="route('game', $game)" :attributes="$attributes">
    <span class="flex shrink-0 -space-x-2">
        @if ($away)
            <x-team-logo :team="$away" size="sm" />
        @endif
        @if ($home)
            <x-team-logo :team="$home" size="sm" />
        @endif
        @if (! $away && ! $home)
            <flux:icon name="calendar-days" variant="mini" class="size-8 shrink-0 p-1.5 text-zinc-400" />
        @endif
    </span>

    <span class="min-w-0 flex-1">
        <span class="block truncate text-sm">{{ $matchup }}</span>

        @if ($subtext !== '')
            <span class="block truncate text-micro text-zinc-500">{{ $subtext }}</span>
        @endif
    </span>
</x-search.row>
