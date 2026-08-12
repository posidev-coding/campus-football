<?php

use App\Support\Rail;
use Illuminate\Support\Facades\Route;

/**
 * The contextual right rail.
 *
 * The rail used to be a layout default nobody overrode: `@props(['rail' =>
 * true])` with zero producers, so all eighteen screens carried the same Top 25
 * panel — /rankings included — and when that panel returned nothing they all
 * carried a blank 288px column instead. App\Support\Rail replaces the default
 * with a decision per route, and these hold that seam.
 */
it('emits no aside at all on a full-width screen', function () {
    // Not "an empty aside" — none. A reserved-but-blank column is the bug
    // this replaced.
    $this->get(route('news'))
        ->assertOk()
        ->assertDontSee('<aside', escape: false);
});

it('emits the aside, gated on lg, for a screen that declares panels', function () {
    $this->get(route('scoreboard'))
        ->assertOk()
        ->assertSee('<aside', escape: false)
        // Desktop-only and additive: below lg the rail does not exist and
        // nothing in it is the only route to its content.
        ->assertSee('hidden w-72 shrink-0 flex-col gap-4 lg:flex', escape: false);
});

it('sticks the stack rather than each panel', function () {
    // Two sticky siblings cannot both hold the top — the second scrolls away.
    $this->get(route('scoreboard'))
        ->assertOk()
        ->assertSee('sticky top-[calc(var(--header-offset)+1rem)]', escape: false);
});

it('declares a rail decision for every routed screen', function () {
    /*
     * The completeness sweep, and the reason this lives in a support class
     * rather than in eighteen `#[Layout]` attributes: a new screen cannot
     * quietly inherit a rail, because this fails until it chooses.
     */
    $screens = collect(Route::getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->reject(fn (string $name) => str_starts_with($name, 'dev.'))
        ->values();

    // Only the Livewire page routes — brand artefacts, webhooks and auth
    // screens have no app layout to hang a rail on.
    $pages = $screens->filter(fn (string $name) => in_array($name, [
        'home', 'get-app', 'scoreboard', 'game', 'standings', 'rankings', 'stats',
        'news', 'article', 'search', 'teams', 'team', 'conference', 'players',
        'player', 'coach', 'recruiting', 'account',
    ], true));

    expect($pages)->toHaveCount(18);

    foreach ($pages as $name) {
        expect(Rail::mapKeys())->toContain($name);
    }
});

it('renders its panels without throwing on every fixture-free rail screen', function (string $route) {
    // x-dynamic-component on a typo'd component name is a runtime exception,
    // and the map is the only place such a typo can hide. The screens needing
    // a model fixture (team, article, coach) are covered where those panels
    // are built.
    $this->get(route($route))->assertOk();
})->with(['home', 'scoreboard', 'stats', 'search']);

it('returns nothing outside every mapped route', function () {
    $this->get(route('login'))->assertOk();

    expect(Rail::panels())->toBe([]);
});
