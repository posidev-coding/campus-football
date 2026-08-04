<?php

use App\Support\Search;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The search bar at the top of Home, and the full-screen panel it expands into.
 *
 * Search lost its bottom-nav tab to make room for Pick'em, so this bar is where
 * a phone searches now. The panel expands IN PLACE around the same input rather
 * than navigating to /search: programmatic focus does not raise the mobile
 * keyboard (the account handle field already paid for that lesson), so the only
 * way the keyboard stays up is if the input the user tapped never goes away.
 *
 * Mobile-only (`sm:hidden`): from `sm` up the header carries the ⌘K palette,
 * and rendering both would put two live search inputs on one screen — the same
 * class of collision as the two listboxes that cross-wired on Account.
 */
new class extends Component
{
    public string $q = '';

    public function clear(): void
    {
        $this->q = '';
    }

    #[Computed]
    public function teams()
    {
        return Search::teams($this->q, limit: 6);
    }

    #[Computed]
    public function players()
    {
        return Search::players($this->q, limit: 6);
    }

    #[Computed]
    public function coaches()
    {
        return Search::coaches($this->q, limit: 4);
    }

    #[Computed]
    public function conferences()
    {
        return Search::conferences($this->q, limit: 4);
    }

    #[Computed]
    public function games()
    {
        return Search::games($this->q, limit: 5);
    }
}; ?>

<div
    x-data="{ open: false }"
    @keydown.escape.window="if (open) { open = false; $wire.clear(); document.activeElement?.blur() }"
    class="sm:hidden"
>
    {{-- One wrapper that is either a row in Home's flow or the whole viewport.
         Toggling classes on the SAME element keeps the input mounted and
         focused across the change, which is what keeps the keyboard up.
         z-50 clears the app chrome at z-40. --}}
    <div
        :class="open
            ? 'fixed inset-0 z-50 flex flex-col bg-white pt-[env(safe-area-inset-top)] dark:bg-zinc-950'
            : ''"
    >
        <div class="flex items-center gap-2" :class="open && 'border-b border-zinc-200 px-4 py-2 dark:border-zinc-800'">
            <flux:input
                wire:model.live.debounce.200ms="q"
                @focus="open = true"
                icon="magnifying-glass"
                placeholder="Teams, players, coaches, games…"
                clearable
                class="flex-1"
            />

            {{-- The one-tap way out. Escape works too, but a phone has no
                 Escape key. Clears the query so reopening starts fresh. --}}
            <flux:button
                x-cloak
                x-show="open"
                @click="open = false; $wire.clear()"
                variant="ghost"
                size="sm"
                class="shrink-0"
            >
                Cancel
            </flux:button>
        </div>

        {{-- `overscroll-contain` stops a fling at the list's boundary from
             scrolling the page underneath — which would otherwise need a body
             scroll lock, and a class toggled onto <html> strands there when a
             result link navigates away mid-open. --}}
        <div x-cloak x-show="open" class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-3 pb-[calc(env(safe-area-inset-bottom)+0.75rem)]">
            @include('partials.search-results', [
                'q' => $q,
                'teams' => $this->teams,
                'players' => $this->players,
                'coaches' => $this->coaches,
                'conferences' => $this->conferences,
                'games' => $this->games,
            ])
        </div>
    </div>
</div>
