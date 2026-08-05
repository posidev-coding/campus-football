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

{{--
    The bar sticks to the top of the screen so search is one tap away however
    far Home has been scrolled — below `sm` there is no header for it to sit
    under, so `top-0` is the real top of the viewport.

    It carries the SAME surface as the layout header — `bg-white/85` with a
    backdrop blur and a zinc bottom rule — because below `sm` that header is
    hidden and this bar is what a phone has instead. Matching it makes the two
    one object at two widths rather than two pieces of chrome with different
    ideas; the rule is what separates the screen from the bar, and the blur is
    what says something is passing underneath. Neutral throughout: no tint, no
    brand color, nothing that competes with the tab bar for attention.

    Translucency is safe here only because the stacking order is right. The
    scoreboard's day headings taught that an opaque background does not win a
    z-index tie — this sits at z-30, above card contents at z-10 and below app
    chrome at z-40, so it wins on z-index and the blur is decoration rather
    than the thing holding the layer together.

    Sticky offsets have to have nothing to travel through, so the container's
    own padding is cancelled and re-applied INSIDE the sticky box: `-mx-4 px-4`
    to reach both screen edges, `-mt-5 pt-5` so the space above travels with the
    bar instead of scrolling away. `pb-3 -mb-3` gives content a gap to disappear
    into without changing Home's `gap-6` rhythm.
--}}
<div
    x-data="{ open: false }"
    @keydown.escape.window="if (open) { open = false; $wire.clear(); document.activeElement?.blur() }"
    class="sticky top-0 z-30 -mx-4 -mt-5 -mb-3 border-b border-zinc-200 bg-white/85 px-4 pt-5 pb-3 backdrop-blur sm:hidden dark:border-zinc-800 dark:bg-zinc-950/85"
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
