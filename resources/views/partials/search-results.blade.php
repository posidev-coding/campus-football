{{--
    Search results, shared by the Home search panel and the /search page — one
    set of rows, so a deep-linked search and an in-place one can never drift.

    Expects $q plus collections $teams, $players, $coaches, $conferences,
    $games and $recruits. Callers pass their own limits; this only renders what
    it is given.

    The rows are rich and the groups are ordered by who gets asked for most,
    but the CONTENT stays factual — search serves Scores and League, so only
    the empty state speaks in the reader's register.
--}}

@php
    $hasResults = $teams->isNotEmpty() || $players->isNotEmpty() || $coaches->isNotEmpty()
        || $conferences->isNotEmpty() || $games->isNotEmpty() || $recruits->isNotEmpty();
@endphp

<div class="flex flex-col gap-4">
    @if (App\Support\Search::tooShort($q))
        <flux:callout icon="magnifying-glass">
            <flux:callout.heading>Search Campus Football</flux:callout.heading>
            <flux:callout.text>
                Teams, players, recruits, coaches, conferences and games. Type at least two characters.
            </flux:callout.text>
        </flux:callout>
    @elseif (! $hasResults)
        <flux:callout icon="magnifying-glass">
            <flux:callout.heading>Nothing found</flux:callout.heading>
            <flux:callout.text>
                {{ App\Support\Voice::line('search.empty', ['query' => trim($q)]) }}
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($teams->isNotEmpty())
        <div class="flex flex-col gap-1">
            <flux:subheading>Teams</flux:subheading>

            @foreach ($teams as $team)
                <x-search.team-row :team="$team" wire:key="sr-team-{{ $team->id }}" />
            @endforeach
        </div>
    @endif

    @if ($players->isNotEmpty())
        <div class="flex flex-col gap-1">
            <flux:subheading>Players</flux:subheading>

            @foreach ($players as $athlete)
                <x-search.player-row :athlete="$athlete" wire:key="sr-player-{{ $athlete->id }}" />
            @endforeach
        </div>
    @endif

    {{-- Straight after Players, because it is the same question asked of people
         who have not enrolled yet. The group only ever holds prospects a player
         search cannot reach — see Search::recruits(). --}}
    @if ($recruits->isNotEmpty())
        <div class="flex flex-col gap-1">
            <flux:subheading>Recruits</flux:subheading>

            @foreach ($recruits as $recruit)
                <x-search.recruit-row :recruit="$recruit" wire:key="sr-recruit-{{ $recruit->id }}" />
            @endforeach
        </div>
    @endif

    @if ($coaches->isNotEmpty())
        <div class="flex flex-col gap-1">
            <flux:subheading>Coaches</flux:subheading>

            @foreach ($coaches as $coach)
                <x-search.coach-row :coach="$coach" wire:key="sr-coach-{{ $coach->id }}" />
            @endforeach
        </div>
    @endif

    @if ($conferences->isNotEmpty())
        <div class="flex flex-col gap-1">
            <flux:subheading>Conferences</flux:subheading>

            @foreach ($conferences as $conference)
                <x-search.conference-row :conference="$conference" wire:key="sr-conf-{{ $conference->id }}" />
            @endforeach
        </div>
    @endif

    @if ($games->isNotEmpty())
        <div class="flex flex-col gap-1">
            <flux:subheading>Games</flux:subheading>

            @foreach ($games as $game)
                <x-search.game-row :game="$game" wire:key="sr-game-{{ $game->id }}" />
            @endforeach
        </div>
    @endif
</div>
