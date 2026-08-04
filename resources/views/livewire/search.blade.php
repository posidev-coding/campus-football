<?php

use App\Models\Athlete;
use App\Models\Conference;
use App\Models\Team;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Global search across teams, players and conferences.
 *
 * Entirely local — 854 teams, ~14,000 athletes and 115 conferences all live in
 * our own database, so this costs one indexed query per group and never touches
 * ESPN. ESPN does publish a search endpoint, but using it would put an external
 * dependency on the fastest interaction in the app.
 *
 * Matching is prefix-first: a leading wildcard cannot use an index, and on the
 * athletes table that is the difference between an index range scan and reading
 * every row. "Geo" finds Georgia; "eorgia" deliberately does not.
 */
new class extends Component
{
    public string $q = '';

    /** Below this a query matches most of the database and is not useful. */
    private const MIN_LENGTH = 2;

    public function clear(): void
    {
        $this->q = '';
    }

    private function tooShort(): bool
    {
        return mb_strlen(trim($this->q)) < self::MIN_LENGTH;
    }

    #[Computed]
    public function teams()
    {
        if ($this->tooShort()) {
            return collect();
        }

        $term = trim($this->q);

        return Team::query()
            ->where(fn ($q) => $q
                ->where('display_name', 'like', $term.'%')
                ->orWhere('location', 'like', $term.'%')
                ->orWhere('nickname', 'like', $term.'%')
                ->orWhere('abbreviation', 'like', $term.'%'))
            ->orderBy('display_name')
            ->limit(6)
            ->get(['id', 'slug', 'display_name', 'short_display_name', 'abbreviation', 'logo', 'logo_dark']);
    }

    #[Computed]
    public function players()
    {
        if ($this->tooShort()) {
            return collect();
        }

        $term = trim($this->q);

        return Athlete::query()
            ->where(fn ($q) => $q
                ->where('display_name', 'like', $term.'%')
                ->orWhere('last_name', 'like', $term.'%'))
            ->orderBy('display_name')
            ->limit(6)
            ->get(['id', 'slug', 'display_name', 'short_name', 'headshot_url']);
    }

    #[Computed]
    public function conferences()
    {
        if ($this->tooShort()) {
            return collect();
        }

        $term = trim($this->q);

        return Conference::query()
            ->where('is_conference', true)
            ->where(fn ($q) => $q
                ->where('name', 'like', $term.'%')
                ->orWhere('short_name', 'like', $term.'%'))
            ->orderBy('name')
            ->limit(4)
            ->get(['id', 'name', 'short_name', 'abbreviation', 'logo']);
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
                        {{ mb_strlen(trim($q)) < 2 ? 'Type at least two characters.' : 'Nothing found for that.' }}
                    </div>
                @endif
            </flux:command.items>
        </flux:command>
    </flux:modal>
</div>
