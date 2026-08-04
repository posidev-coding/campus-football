<?php

use App\Support\SearchIndex;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The ⌘K command palette.
 *
 * Desktop-only: on a phone, Search is a bottom-nav area with its own full
 * screen, which is a better fit for a thumb and a soft keyboard than a modal.
 * Both read the same SearchIndex so the two can never drift apart.
 */
new class extends Component
{
    public string $q = '';

    #[Computed]
    public function teams()
    {
        return SearchIndex::teams($this->q);
    }

    #[Computed]
    public function players()
    {
        return SearchIndex::players($this->q);
    }

    #[Computed]
    public function conferences()
    {
        return SearchIndex::conferences($this->q);
    }

    #[Computed]
    public function hasResults(): bool
    {
        return $this->teams->isNotEmpty()
            || $this->players->isNotEmpty()
            || $this->conferences->isNotEmpty();
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
                placeholder="Search teams, players, conferences…"
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

                @if (! $this->hasResults)
                    <div class="px-3 py-6 text-center text-sm text-zinc-500">
                        {{ App\Support\SearchIndex::tooShort($q) ? 'Type at least two characters.' : 'Nothing found for that.' }}
                    </div>
                @endif
            </flux:command.items>
        </flux:command>
    </flux:modal>
</div>
