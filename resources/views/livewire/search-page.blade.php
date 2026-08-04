<?php

use App\Support\SearchIndex;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The /search screen. No longer a bottom-nav area — the bar at the top of Home
 * is where a phone starts a search now — but the route survives for deep links
 * and shared URLs, rendering the same result rows the Home panel does.
 *
 * The query lives in the URL so a search is shareable and survives the back
 * button.
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
}; ?>

<div class="flex flex-col gap-4">
    <h1 class="sr-only">Search</h1>

    {{-- Autofocused so the keyboard is up the moment the screen opens: a
         search screen that needs a second tap to start typing wastes the trip. --}}
    <flux:input
        wire:model.live.debounce.200ms="q"
        icon="magnifying-glass"
        placeholder="Teams, players, conferences…"
        autofocus
        clearable
    />

    @include('partials.search-results', [
        'q' => $q,
        'teams' => $this->teams,
        'players' => $this->players,
        'conferences' => $this->conferences,
    ])
</div>
