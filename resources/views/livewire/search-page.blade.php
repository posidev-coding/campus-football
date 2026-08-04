<?php

use App\Support\SearchIndex;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Search area's own screen.
 *
 * A full page rather than the ⌘K modal, because on a phone a soft keyboard
 * takes half the viewport and a centred dialog inside what is left is a poor
 * place to read results. Shares SearchIndex with the palette.
 *
 * The query lives in the URL so a search is shareable and survives a back
 * button — which is what a person expects from a tab they can leave and return
 * to.
 */
new class extends Component
{
    #[Url(as: 'q')]
    public string $q = '';

    public function clear(): void
    {
        $this->q = '';
    }

    #[Computed]
    public function teams()
    {
        return SearchIndex::teams($this->q, limit: 10);
    }

    #[Computed]
    public function players()
    {
        return SearchIndex::players($this->q, limit: 10);
    }

    #[Computed]
    public function conferences()
    {
        return SearchIndex::conferences($this->q, limit: 6);
    }

    #[Computed]
    public function hasResults(): bool
    {
        return $this->teams->isNotEmpty()
            || $this->players->isNotEmpty()
            || $this->conferences->isNotEmpty();
    }

    #[Computed]
    public function tooShort(): bool
    {
        return SearchIndex::tooShort($this->q);
    }
}; ?>

<div class="flex flex-col gap-4">
    {{-- Autofocused so the keyboard is up the moment the tab opens: a Search
         area that needs a second tap to start typing wastes the trip. --}}
    <flux:input
        wire:model.live.debounce.200ms="q"
        icon="magnifying-glass"
        placeholder="Teams, players, conferences…"
        autofocus
        clearable
    />

    @if ($this->tooShort)
        <flux:callout icon="magnifying-glass">
            <flux:callout.heading>Search Campus Football</flux:callout.heading>
            <flux:callout.text>
                Every team, player and conference. Type at least two characters.
            </flux:callout.text>
        </flux:callout>
    @elseif (! $this->hasResults)
        <flux:callout icon="magnifying-glass">
            <flux:callout.heading>Nothing found</flux:callout.heading>
            <flux:callout.text>
                Try the start of a name — matching is from the beginning, so
                &ldquo;Geo&rdquo; finds Georgia.
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->teams->isNotEmpty())
        <div class="flex flex-col gap-1">
            <flux:subheading>Teams</flux:subheading>

            @foreach ($this->teams as $team)
                <a
                    href="{{ route('team', $team) }}"
                    wire:navigate
                    wire:key="sp-team-{{ $team->id }}"
                    class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 transition-colors hover:border-zinc-300 dark:border-zinc-800 dark:hover:border-zinc-700"
                >
                    <x-team-logo :team="$team" size="sm" />
                    <span class="min-w-0 flex-1 truncate text-sm">{{ $team->display_name }}</span>
                    <flux:icon name="chevron-right" variant="micro" class="shrink-0 text-zinc-400" />
                </a>
            @endforeach
        </div>
    @endif

    @if ($this->players->isNotEmpty())
        <div class="flex flex-col gap-1">
            <flux:subheading>Players</flux:subheading>

            @foreach ($this->players as $athlete)
                <a
                    href="{{ route('player', $athlete) }}"
                    wire:navigate
                    wire:key="sp-player-{{ $athlete->id }}"
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

    @if ($this->conferences->isNotEmpty())
        <div class="flex flex-col gap-1">
            <flux:subheading>Conferences</flux:subheading>

            @foreach ($this->conferences as $conference)
                <a
                    href="{{ route('conference', $conference) }}"
                    wire:navigate
                    wire:key="sp-conf-{{ $conference->id }}"
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
