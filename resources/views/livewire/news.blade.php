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

    @forelse ($this->articles as $article)
        <x-article-card :article="$article" wire:key="article-{{ $article->id }}" />
    @empty
        <flux:callout icon="newspaper">
            <flux:callout.heading>No news yet</flux:callout.heading>
            <flux:callout.text>
                Nothing synced. Run <code>php artisan cfb:sync --only=news</code>.
            </flux:callout.text>
        </flux:callout>
    @endforelse

    {{ $this->articles->links() }}
</div>
