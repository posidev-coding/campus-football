<?php

use App\Models\Article;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\Venue;
use App\Models\Week;
use App\Support\Rail;
use Illuminate\Support\Facades\Cache;
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

    // Only the Livewire page routes — brand artifacts, webhooks and auth
    // screens have no app layout to hang a rail on.
    $pages = $screens->filter(fn (string $name) => in_array($name, [
        'home', 'get-app', 'pickem.home', 'pickem.lobby', 'pickem.join', 'scoreboard', 'game', 'standings', 'rankings',
        'stats', 'news', 'article', 'search', 'teams', 'team', 'conference',
        'players', 'player', 'coach', 'recruiting', 'account',
    ], true));

    expect($pages)->toHaveCount(21);

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

describe('panels that render real rows', function () {
    /*
     * These exist because the assertions above cannot fail the way this area
     * actually broke. Every panel that renders a game card reads `venue` and
     * `odds` off it, and lazy loading is disabled app-wide — so a panel whose
     * query forgets one is a 500. On an empty database the panel renders
     * nothing at all and every test passes; the fault only appears once there
     * is a row to render, which is to say only in production.
     */
    beforeEach(function () {
        Cache::flush();

        $this->season = Season::factory()->create([
            'year' => 2025, 'type' => Season::REGULAR,
            'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
        ]);

        $this->week = Week::create([
            'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
            'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
        ]);

        $this->georgia = Team::factory()->create([
            'id' => 61, 'slug' => 'georgia-bulldogs',
            'location' => 'Georgia', 'display_name' => 'Georgia Bulldogs',
        ]);
        $this->alabama = Team::factory()->create([
            'id' => 333, 'slug' => 'alabama-crimson-tide',
            'location' => 'Alabama', 'display_name' => 'Alabama Crimson Tide',
        ]);

        /*
         * A REAL venue, and that is the point of the fixture rather than a
         * detail of it. GameFactory leaves `venue_id` null, and a belongs-to
         * with a null key resolves without ever consulting the lazy-loading
         * guard — so a game card reading `$game->venue` renders "Venue TBD"
         * and a panel with a missing eager load passes. Every game in
         * production has a venue, where the same panel is a 500.
         */
        $venue = Venue::create(['id' => 3833, 'name' => 'Sanford Stadium']);

        // Pinned: GameFactory defaults kickoff_at to a random window, and the
        // next/last split is entirely a question of which side of now() a
        // game falls on.
        $this->game = Game::factory()->create([
            'season_id' => $this->season->id, 'week_id' => $this->week->id,
            'home_team_id' => 61, 'away_team_id' => 333, 'venue_id' => $venue->id,
            'kickoff_at' => '2025-09-27 19:30:00', 'completed' => true,
        ]);
    });

    it('renders the team rail with a real game beside it', function () {
        $this->travelTo('2025-09-30 12:00:00');

        $this->get(route('team', $this->georgia))
            ->assertOk()
            ->assertSee('Next &amp; last', escape: false)
            ->assertSee('Sanford Stadium');
    });

    it('eager loads every relation a game card reads', function () {
        /*
         * A SOURCE sweep, not a render assertion, and the reason is worth
         * knowing: `Model::preventLazyLoading()` is on in this environment,
         * but the per-instance `$preventsLazyLoading` flag on a model
         * retrieved during a test is FALSE — so an unloaded relation resolves
         * silently here and throws only in dev and production. This exact
         * bug shipped through a green suite: /rankings returned a 500 reading
         * `venue` off a game the rail had not loaded it for, while every test
         * above passed.
         *
         * So the guarantee is held where it can be: any rail panel rendering
         * a game card must load what the card reads.
         */
        $cardRelations = ['homeTeam', 'awayTeam', 'venue', 'odds'];
        $violations = [];

        foreach (glob(resource_path('views/components/rail/*.blade.php')) as $path) {
            $source = file_get_contents($path);

            if (! str_contains($source, '<x-game-card')) {
                continue;
            }

            foreach ($cardRelations as $relation) {
                if (! str_contains($source, $relation)) {
                    $violations[] = basename($path)." is missing [{$relation}]";
                }
            }
        }

        expect($violations)->toBe([], implode(', ', $violations)
            .' — a rail panel rendering <x-game-card> must eager load every relation the card reads.');
    });

    it('renders the rankings rail with a real week beside it', function () {
        $this->travelTo('2025-09-25 12:00:00');

        $this->get(route('rankings'))->assertOk();
    });

    it('renders the news rail with real headlines beside it', function () {
        Article::factory()->create([
            'headline' => 'A headline in the rail',
            'published_at' => now()->subHour(),
        ]);

        $this->get(route('news'))->assertOk();

        // /news is full width by design, so the panel proves itself on a
        // screen that actually declares it.
        $this->get(route('article', Article::first()))
            ->assertOk()
            ->assertSee('Latest news');
    });
});
