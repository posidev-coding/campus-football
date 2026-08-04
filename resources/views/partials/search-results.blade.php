{{--
    Search results, shared by the Home search panel and the /search page — one
    set of rows, so a deep-linked search and an in-place one can never drift.

    Expects: $q (string), plus collections $teams, $players, $conferences.
    Callers pass their own limits; this only renders what it is given.
--}}

@php
    $tooShort = App\Support\SearchIndex::tooShort($q);
    $hasResults = $teams->isNotEmpty() || $players->isNotEmpty() || $conferences->isNotEmpty();
@endphp

<div class="flex flex-col gap-4">
    @if ($tooShort)
        <flux:callout icon="magnifying-glass">
            <flux:callout.heading>Search Campus Football</flux:callout.heading>
            <flux:callout.text>
                Every team, player and conference. Type at least two characters.
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
                <a
                    href="{{ route('team', $team) }}"
                    wire:navigate
                    wire:key="sr-team-{{ $team->id }}"
                    class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 transition-colors hover:border-zinc-300 dark:border-zinc-800 dark:hover:border-zinc-700"
                >
                    <x-team-logo :team="$team" size="sm" />
                    <span class="min-w-0 flex-1 truncate text-sm">{{ $team->display_name }}</span>
                    <flux:icon name="chevron-right" variant="micro" class="shrink-0 text-zinc-400" />
                </a>
            @endforeach
        </div>
    @endif

    @if ($players->isNotEmpty())
        <div class="flex flex-col gap-1">
            <flux:subheading>Players</flux:subheading>

            @foreach ($players as $athlete)
                <a
                    href="{{ route('player', $athlete) }}"
                    wire:navigate
                    wire:key="sr-player-{{ $athlete->id }}"
                    class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 transition-colors hover:border-zinc-300 dark:border-zinc-800 dark:hover:border-zinc-700"
                >
                    @if ($athlete->headshot_url)
                        <img src="{{ $athlete->headshot_url }}" alt="" loading="lazy" class="size-7 shrink-0 rounded-full object-cover">
                    @else
                        <flux:icon name="user" variant="micro" class="size-7 shrink-0 rounded-full bg-zinc-100 p-1.5 text-zinc-400 dark:bg-zinc-800" />
                    @endif
                    <span class="min-w-0 flex-1 truncate text-sm">{{ $athlete->display_name }}</span>
                    <flux:icon name="chevron-right" variant="micro" class="shrink-0 text-zinc-400" />
                </a>
            @endforeach
        </div>
    @endif

    @if ($conferences->isNotEmpty())
        <div class="flex flex-col gap-1">
            <flux:subheading>Conferences</flux:subheading>

            @foreach ($conferences as $conference)
                <a
                    href="{{ route('conference', $conference) }}"
                    wire:navigate
                    wire:key="sr-conf-{{ $conference->id }}"
                    class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 transition-colors hover:border-zinc-300 dark:border-zinc-800 dark:hover:border-zinc-700"
                >
                    @if ($conference->logo)
                        <img src="{{ $conference->logo }}" alt="" loading="lazy" class="size-7 shrink-0 object-contain">
                    @else
                        <flux:icon name="trophy" variant="micro" class="size-7 shrink-0 p-1.5 text-zinc-400" />
                    @endif
                    <span class="min-w-0 flex-1 truncate text-sm">{{ $conference->name }}</span>
                    <flux:icon name="chevron-right" variant="micro" class="shrink-0 text-zinc-400" />
                </a>
            @endforeach
        </div>
    @endif
</div>
