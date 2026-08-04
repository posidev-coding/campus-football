<?php

use App\Support\Search;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The ⌘K command palette.
 *
 * Desktop-only: on a phone, the bar at the top of Home expands into the
 * full-screen panel instead. All three surfaces read App\Support\Search, so
 * they can never drift on WHAT is found — this one renders flux:command.item
 * rows rather than the shared partial because arrow-key navigation is the
 * point of a palette, and that is what command items provide.
 */
new class extends Component
{
    public string $q = '';

    #[Computed]
    public function teams()
    {
        return Search::teams($this->q);
    }

    #[Computed]
    public function players()
    {
        return Search::players($this->q);
    }

    #[Computed]
    public function coaches()
    {
        return Search::coaches($this->q);
    }

    #[Computed]
    public function conferences()
    {
        return Search::conferences($this->q);
    }

    #[Computed]
    public function games()
    {
        return Search::games($this->q);
    }

    #[Computed]
    public function hasResults(): bool
    {
        return $this->teams->isNotEmpty()
            || $this->players->isNotEmpty()
            || $this->coaches->isNotEmpty()
            || $this->conferences->isNotEmpty()
            || $this->games->isNotEmpty();
    }
}; ?>

<div>
    <flux:modal.trigger name="search" shortcut="cmd.k">
        <flux:button icon="magnifying-glass" size="sm" variant="ghost" inset aria-label="Search" />
    </flux:modal.trigger>

    <flux:modal name="search" variant="bare" class="my-[10vh] max-h-screen w-full max-w-[32rem] overflow-y-hidden">
        <flux:command class="inline-flex max-h-[70vh] flex-col border-none shadow-lg">
            <flux:command.input
                wire:model.live.debounce.200ms="q"
                placeholder="Search teams, players, coaches, games…"
                closable
            />

            <flux:command.items>
                @if ($this->teams->isNotEmpty())
                    <x-search-heading>Teams</x-search-heading>

                    @foreach ($this->teams as $team)
                        <flux:command.item
                            href="{{ route('team', $team) }}"
                            wire:navigate
                            wire:key="s-team-{{ $team->id }}"
                        >
                            <span class="flex items-center gap-2">
                                <x-team-logo :team="$team" size="sm" />
                                {{ $team->display_name }}
                            </span>
                        </flux:command.item>
                    @endforeach
                @endif

                @if ($this->players->isNotEmpty())
                    <x-search-heading>Players</x-search-heading>

                    @foreach ($this->players as $athlete)
                        <flux:command.item
                            href="{{ route('player', $athlete) }}"
                            wire:navigate
                            icon="user"
                            wire:key="s-player-{{ $athlete->id }}"
                        >{{ $athlete->display_name }}</flux:command.item>
                    @endforeach
                @endif

                @if ($this->coaches->isNotEmpty())
                    <x-search-heading>Coaches</x-search-heading>

                    @foreach ($this->coaches as $coach)
                        <flux:command.item
                            href="{{ route('coach', $coach) }}"
                            wire:navigate
                            icon="academic-cap"
                            wire:key="s-coach-{{ $coach->id }}"
                        >{{ $coach->display_name }}</flux:command.item>
                    @endforeach
                @endif

                @if ($this->conferences->isNotEmpty())
                    <x-search-heading>Conferences</x-search-heading>

                    @foreach ($this->conferences as $conference)
                        <flux:command.item
                            href="{{ route('conference', $conference) }}"
                            wire:navigate
                            icon="trophy"
                            wire:key="s-conf-{{ $conference->id }}"
                        >{{ $conference->name }}</flux:command.item>
                    @endforeach
                @endif

                @if ($this->games->isNotEmpty())
                    <x-search-heading>Games</x-search-heading>

                    @foreach ($this->games as $game)
                        <flux:command.item
                            href="{{ route('game', $game) }}"
                            wire:navigate
                            icon="calendar-days"
                            wire:key="s-game-{{ $game->id }}"
                        >{{ $game->name }}</flux:command.item>
                    @endforeach
                @endif

                @if (! $this->hasResults)
                    <div class="px-3 py-6 text-center text-sm text-zinc-500">
                        {{ App\Support\Search::tooShort($q) ? 'Type at least two characters.' : 'Nothing found for that.' }}
                    </div>
                @endif
            </flux:command.items>
        </flux:command>
    </flux:modal>
</div>
