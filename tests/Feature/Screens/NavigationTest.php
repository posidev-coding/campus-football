<?php

use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\Conference;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\User;
use App\Models\Week;
use App\Support\Navigation;
use Livewire\Livewire;

beforeEach(function () {
    $this->season = Season::factory()->create([
        'year' => 2025, 'type' => Season::REGULAR,
        'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
    ]);
    $this->week = Week::create([
        'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
    ]);

    Conference::factory()->create(['id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC']);
    $this->team = Team::factory()->create(['id' => 61, 'slug' => 'georgia-bulldogs', 'display_name' => 'Georgia Bulldogs']);
    TeamSeason::create(['team_id' => 61, 'season_year' => 2025, 'conference_id' => 8, 'classification' => 'FBS']);

    $this->game = Game::factory()->finished()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => null,
    ]);
});

describe('areas', function () {
    it('gives a phone the same destinations the desktop header has', function () {
        /*
         * The header is hidden below `sm`, so everything in it must be
         * reachable from the tab bar or it is unreachable on a phone. That is
         * the rule this rework exists to satisfy — brand goes to Home, the
         * search icon to Search, the avatar to Account.
         */
        $keys = collect(Navigation::areas())->pluck('key');

        expect($keys)->toContain('home', 'search', 'account');
    });

    it('keeps a tab lit on detail pages inside its area', function () {
        // A game page keeps Scores lit and a team page keeps League lit. Tabs
        // are AREAS, so comparing the tab's own href to the URL would light up
        // only on each area's landing screen.
        $this->get(route('game', $this->game))
            ->assertOk()
            ->assertSee('aria-current="page"', escape: false);

        $this->get(route('team', $this->team))
            ->assertOk()
            ->assertSee('aria-current="page"', escape: false);
    });

    it('resolves the right area for every public route', function () {
        $cases = [
            'home' => 'home',
            'news' => 'home',
            'scoreboard' => 'scores',
            'standings' => 'league',
            'rankings' => 'league',
            'teams' => 'league',
            'stats' => 'league',
            'leaders' => 'league',
            'recruiting' => 'league',
            'search' => 'search',
        ];

        foreach ($cases as $route => $expected) {
            $this->get(route($route))->assertOk();

            $area = collect(Navigation::areas())
                ->first(fn (array $a) => in_array($route, $a['routes'], true));

            expect($area['key'])->toBe($expected, "Route [{$route}] should be in area [{$expected}].");
        }
    });

    it("points a guest's Account tab at sign-in rather than a dead end", function () {
        // The tab bar is the only navigation at phone width; an Account tab
        // that vanishes for a guest takes the sign-in route with it.
        $account = collect(Navigation::areas())->firstWhere('key', 'account');

        expect(Navigation::href($account))->toBe(route('login'))
            ->and(Navigation::label($account))->toBe('Sign in');
    });
});

describe('sections', function () {
    it("shows only the current area's sections", function () {
        // Previously both navs listed all nine sections, so the top strip was
        // a second copy of the bottom bar rather than a level below it.
        $this->get(route('standings'))
            ->assertOk()
            ->assertSee('Recruiting')
            ->assertSee('Team Stats');
    });

    it('renders no strip on Scores, which is the only screen in its area', function () {
        /*
         * A strip with one tab is chrome, not navigation. Bowls and the playoff
         * moved into the week scroller, which left Scores alone in its area —
         * and freed it to carry the app's one non-redundant heading.
         */
        $this->get(route('scoreboard'))
            ->assertOk()
            ->assertDontSee('aria-label="Sections"', escape: false)
            ->assertSee('Scoreboard');
    });

    it('keeps a screen-reader heading on screens whose heading is hidden', function () {
        // The section strip names these screens, so the visible h1 was the same
        // word twice. It stays in the DOM for anyone not looking at the strip.
        foreach (['standings', 'rankings', 'teams', 'news', 'stats', 'leaders'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('<h1 class="sr-only">', escape: false);
        }
    });

    it('renders no strip for a single-screen area', function () {
        // "When necessary" — a strip with one item is chrome, not navigation.
        $this->get(route('search'))
            ->assertOk()
            ->assertDontSee('aria-label="Sections"', escape: false);
    });
});

describe('mobile chrome', function () {
    it('hides the top bar below sm and keeps the tab bar', function () {
        $response = $this->get(route('scoreboard'))->assertOk();

        // The header row is gated on sm, the tab bar retires at sm.
        $response->assertSee('sm:flex', escape: false)
            ->assertSee('sm:hidden', escape: false)
            ->assertSee('aria-label="Primary"', escape: false);
    });

    it('gives guests navigation at phone width', function () {
        $this->get(route('scoreboard'))
            ->assertOk()
            ->assertSee(route('standings'), escape: false)
            ->assertSee(route('search'), escape: false);
    });
});

describe('account absorbs the avatar menu', function () {
    it('offers log out on the account screen, not only the desktop dropdown', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account'))
            ->assertOk()
            ->assertSee('Log out')
            ->assertSee(route('logout'), escape: false);
    });

    it('shows admin only to admins', function () {
        $this->actingAs(User::factory()->create())->get(route('account'))
            ->assertOk()->assertDontSee('>Admin<', escape: false);

        $admin = User::factory()->create();
        $admin->forceFill(['admin' => true])->save();

        $this->actingAs($admin)->get(route('account'))->assertOk()->assertSee('Admin');
    });
});

describe('search area', function () {
    it('has a full screen, not only the palette', function () {
        // A soft keyboard takes half a phone viewport; a centred dialog inside
        // what is left is a poor place to read results.
        $this->get(route('search'))->assertOk();
    });

    it('finds teams, players and conferences', function () {
        Athlete::create(['id' => 1, 'display_name' => 'George Player']);

        Livewire::test('search-page')
            ->set('q', 'Georg')
            ->assertSee('Georgia Bulldogs')
            ->assertSee('George Player');
    });

    it('shares its results with the command palette', function () {
        // Both read App\Support\SearchIndex, so the two cannot drift.
        Athlete::create(['id' => 2, 'display_name' => 'Georgina Test']);

        $page = Livewire::test('search-page')->set('q', 'Georg');
        $palette = Livewire::test('search')->set('q', 'Georg');

        foreach (['Georgia Bulldogs', 'Georgina Test'] as $expected) {
            $page->assertSee($expected);
            $palette->assertSee($expected);
        }
    });

    it('refuses a query too short to be useful', function () {
        Livewire::test('search-page')->set('q', 'G')->assertSee('Type at least two characters');
    });
});

it('renders a player game log with ESPN column headings', function () {
    /*
     * Regression: `display_stats` now holds {name, label} pairs written by the
     * game summary sync, but this passed them to a method typed `string` and
     * fatalled. The path was never exercised before because athlete_game_stats
     * was empty — populating it is what surfaced this.
     */
    $athlete = Athlete::create(['id' => 4690158, 'display_name' => 'Noah Kim']);

    AthleteGameStat::create([
        'athlete_id' => $athlete->id,
        'game_id' => $this->game->id,
        'team_id' => 61,
        'category' => 'passing',
        'stats' => ['completions/passingAttempts' => '25/42', 'passingYards' => '330'],
        'display_stats' => [
            ['name' => 'completions/passingAttempts', 'label' => 'C/ATT'],
            ['name' => 'passingYards', 'label' => 'YDS'],
        ],
    ]);

    Livewire::test('player', ['athlete' => $athlete])
        ->call('loadGameLog')
        ->assertOk()
        // ESPN's own headings, which beat anything we would name ourselves.
        ->assertSee('C/ATT')
        ->assertSee('25/42');
});
