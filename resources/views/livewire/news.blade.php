<?php

use App\Models\Article;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The national news feed.
 *
 * ESPN's feed is a rolling window of roughly six days and clamps `limit` to 50
 * whatever you ask for, so what is shown here is what we have ACCUMULATED by
 * syncing on a schedule — it cannot be backfilled. The sync never deletes, so
 * an article falling out of ESPN's window stays in ours.
 */
new class extends Component
{
    use WithPagination;

    #[Computed]
    public function articles()
    {
        return Article::query()
            ->with('teams:id,slug,short_display_name,abbreviation,logo,logo_dark')
            ->newest()
            ->paginate(20);
    }
}; ?>

<div class="flex flex-col gap-4">
    <h1 class="sr-only">News</h1>

    {{-- An order-preserving grid, never CSS columns: the feed is sorted
         newest-first, and a column flow would read 1-2-3 down the left before
         4-5-6 down the right, which puts the second-newest story below the
         fold and the seventh at the top of the page.

         This screen carries no rail, so the columns are the width's whole
         job — 322px cells at `lg`, 343px at four across the widest shell.
         Cards stretch to their row: `article-card` already pushes its meta
         line down with `mt-auto`, so an equal-height row fills correctly. --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
        @forelse ($this->articles as $article)
            <x-article-card :article="$article" wire:key="article-{{ $article->id }}" />
        @empty
            <flux:callout icon="newspaper" class="sm:col-span-2 lg:col-span-3 2xl:col-span-4">
                <flux:callout.heading>No news yet</flux:callout.heading>
                <flux:callout.text>
                    {{-- Factual, News is a PURE surface. The artisan hint is for the operator alone. --}}
                    Nothing here yet — headlines land as they publish.
                    @if (auth()->user()?->isAdmin())
                        Run <code>php artisan cfb:sync --only=news</code>.
                    @endif
                </flux:callout.text>
            </flux:callout>
        @endforelse
    </div>

    {{-- Outside the grid: a paginator is not a card and must not take a cell. --}}
    {{ $this->articles->links() }}
</div>
