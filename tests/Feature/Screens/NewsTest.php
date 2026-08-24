<?php

use App\Models\Article;
use App\Models\User;

/**
 * The national news feed.
 *
 * The screen had no test at all and no desktop styling — a 20-item stacked
 * column at every width. It is the app's best multi-column candidate: uniform
 * cards, no ranking to preserve across columns, and no rail competing for the
 * space.
 */
beforeEach(function () {
    // Pinned timestamps: `newest()` orders by published_at, and a factory
    // default would reshuffle the feed between runs.
    collect(range(1, 6))->each(fn (int $i) => Article::factory()->create([
        'headline' => "Story number {$i}",
        'published_at' => now()->subHours($i),
    ]));
});

it('renders the feed as an order-preserving grid, not a stack', function () {
    /*
     * CSS columns would pack tighter and read wrong: the feed is sorted
     * newest-first, and a column flow puts story two below the fold and story
     * seven at the top of the page.
     */
    $this->get(route('news'))
        ->assertOk()
        ->assertSee('grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4', escape: false);
});

it('keeps the paginator out of the grid', function () {
    // A paginator taking a card's cell reads as a broken last row. Needs a
    // second page to exist at all — the feed paginates at 20.
    collect(range(7, 26))->each(fn (int $i) => Article::factory()->create([
        'headline' => "Story number {$i}",
        'published_at' => now()->subHours($i),
    ]));

    $html = $this->get(route('news'))->assertOk()->content();

    $gridEnds = strrpos($html, '2xl:grid-cols-4');
    $paginator = strpos($html, 'aria-label="Pagination Navigation"');

    expect($paginator)->not->toBeFalse()
        ->and($paginator)->toBeGreaterThan($gridEnds);
});

it('gives every card its own key so the morph cannot reuse a cell', function () {
    $html = $this->get(route('news'))->assertOk()->content();

    expect(substr_count($html, 'wire:key="article-'))->toBe(6);
});

it('lets a card shrink below its longest headline', function () {
    /*
     * A grid item keeps its min-content width, so a card without `min-w-0`
     * widens its own track and scrolls the document sideways — the failure
     * that reads as the nav coming apart rather than as a text problem.
     */
    $this->get(route('news'))
        ->assertOk()
        ->assertSee('group flex min-w-0 gap-3', escape: false);
});

it('spans the empty state across the whole grid', function () {
    Article::query()->delete();

    $this->get(route('news'))
        ->assertOk()
        ->assertSee('No news yet')
        ->assertSee('sm:col-span-2 lg:col-span-3 2xl:col-span-4', escape: false);
});

it('keeps the artisan hint for the operator alone', function () {
    // A fan cannot run a console command; telling them to reads as the app
    // being broken. Admins keep the remedy.
    Article::query()->delete();

    $this->get(route('news'))
        ->assertOk()
        ->assertDontSee('php artisan');

    $this->actingAs(User::factory()->create(['admin' => true]))
        ->get(route('news'))
        ->assertSee('php artisan cfb:sync');
});
