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
use Illuminate\Support\Facades\DB;
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
         * reachable from the tab bar or it is unreachable on a phone — brand
         * goes to Home, the avatar to Account. Search is deliberately NOT a
         * tab anymore: the bar at the top of Home carries it, and the freed
         * slot went to Picks — center, because the product's point takes the
         * middle of a five-tab bar.
         */
        $keys = collect(Navigation::areas())->pluck('key');

        expect($keys->all())->toBe(['home', 'scores', 'picks', 'league', 'account']);
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
            'pickem.home' => 'picks',
            'pickem.lobby' => 'picks',
            'standings' => 'league',
            'rankings' => 'league',
            'teams' => 'league',
            'players' => 'league',
            'stats' => 'league',
            'recruiting' => 'league',
            // /search survives for deep links, but it is Home's search now.
            'search' => 'home',
            // The install walkthrough is reached from Home's banner.
            'get-app' => 'home',
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
            ->assertSee('Players')
            // Team Stats and Player Stats were two sections answering one
            // question. They are one Stats screen with a sub-toggle now, and
            // the freed slot went to the player index.
            ->assertSee('Stats')
            ->assertDontSee('Team Stats')
            ->assertDontSee('Player Stats');
    });

    it('branches the Picks sections on the config mirror, with zero Pennant rows', function () {
        /*
         * Navigation renders for EVERY visitor in four chrome components,
         * and Pennant's database driver persists a row per resolve — so the
         * sections read the config mirror, byte-identical to the flag
         * closure in AppServiceProvider. Break-back: put Feature::active()
         * back and the zero-rows assertion fails on first render.
         */
        $civilian = User::factory()->create();
        $this->actingAs($civilian);

        config()->set('cfb.pickem_open', false);
        Navigation::flush();
        expect(collect(Navigation::areas())->firstWhere('key', 'picks')['sections'])->toBe([]);

        config()->set('cfb.pickem_open', true);
        Navigation::flush();
        expect(collect(collect(Navigation::areas())->firstWhere('key', 'picks')['sections'])->pluck('label')->all())
            ->toBe(['My Picks', 'Lobby', 'Leaderboard', 'History']);

        // Closed flag, admin: the sections exist for them alone.
        config()->set('cfb.pickem_open', false);
        $this->actingAs(User::factory()->create(['admin' => true]));
        Navigation::flush();
        expect(collect(Navigation::areas())->firstWhere('key', 'picks')['sections'])->not->toBe([]);

        expect(DB::table('features')->count())->toBe(0);
    });

    it('memoizes the structure per viewer within a request', function () {
        $this->actingAs(User::factory()->create());

        $first = Navigation::areas();

        // Same process, same viewer: the same array back, no rebuild. The
        // memo is keyed by auth id, so switching viewers rebuilds.
        expect(Navigation::areas())->toBe($first);
    });

    it('lights the Teams section on an individual team page', function () {
        /*
         * Sections light on their detail pages the same way area tabs do — a
         * team page keeps the Teams chip filled, not just League lit in the
         * tab bar.
         *
         * Asserted INSIDE the Sections nav, sliced between its aria-label and
         * its closing tag, because the strip now speaks the area nav's chip
         * language and the League area tab wears these SAME active-chip
         * classes on every League page (md:flex-hidden, but in the DOM).
         * Page-wide, the chip string counts 2 — the shared vocabulary is the
         * design, not a bug — so a bare substr_count over the page proves
         * nothing about the strip.
         */
        $chip = 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100';

        $strip = str($this->get(route('team', $this->team))->assertOk()->content())
            ->after('aria-label="Sections"')
            ->before('</nav>');

        // Exactly one chip, and the label inside that chip's own link is
        // Teams — chip and label proven to share one element, which the old
        // assertSeeInOrder never quite did.
        expect($strip->substrCount($chip))->toBe(1)
            ->and((string) $strip->after($chip)->before('</a>'))->toContain('Teams');

        /*
         * Exactly one section is current on a plain section screen too — the
         * sections have not started claiming each other's detail pages.
         */
        $strip = str($this->get(route('players'))->assertOk()->content())
            ->after('aria-label="Sections"')
            ->before('</nav>');

        expect($strip->substrCount($chip))->toBe(1);
    });

    it('lights the Players section on an individual player page', function () {
        /*
         * `player` was in the League area's routes but belonged to no section,
         * so a player page lit the League tab with the whole strip unlit —
         * the reader could see they were in League and not where.
         */
        $athlete = Athlete::create([
            'id' => 4242, 'slug' => 'test-player', 'display_name' => 'Test Player',
        ]);

        $strip = str($this->get(route('player', $athlete))->assertOk()->content())
            ->after('aria-label="Sections"')
            ->before('</nav>');

        expect((string) $strip->after('bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100')->before('</a>'))
            ->toContain('Players');
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
        foreach (['standings', 'rankings', 'teams', 'players', 'news', 'stats'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('<h1 class="sr-only">', escape: false);
        }
    });

    it('renders no strip on Home, whose strip gave way to the search bar', function () {
        // "For You" and "News" made a two-tab strip whose first tab was the
        // screen you were already on. The search bar claims that row now, and
        // News is reached through the "More" link on the feed.
        $this->get(route('home'))
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
        // The tab bar renders for guests: League is reachable, and the Account
        // tab resolves to sign-in rather than disappearing.
        $this->get(route('scoreboard'))
            ->assertOk()
            ->assertSee(route('standings'), escape: false)
            ->assertSee(route('login'), escape: false);
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

    it('offers a light/dark/system appearance control', function () {
        /*
         * On Account rather than the header, because the header does not exist
         * below `sm` — anything reachable only from the desktop avatar dropdown
         * is unreachable on a phone, which is the failure the navigation rework
         * exists to remove.
         */
        $response = $this->actingAs(User::factory()->create())
            ->get(route('account'))
            ->assertOk();

        $response->assertSee('Appearance')
            // Flux's own store: it owns the `.dark` class, localStorage, and
            // the OS-preference listener, so "System" keeps tracking rather
            // than freezing at page load.
            ->assertSee('$flux.appearance', escape: false)
            ->assertSee('value="light"', escape: false)
            ->assertSee('value="dark"', escape: false)
            ->assertSee('value="system"', escape: false);
    });

    it('keeps the mobile browser chrome in step with the theme', function () {
        // Hardcoded dark, a phone's address bar stayed black in light mode —
        // which only became visible once the appearance control existed.
        $this->actingAs(User::factory()->create())
            ->get(route('account'))
            ->assertOk()
            ->assertSee('meta[name=theme-color]', escape: false)
            ->assertSee('$flux.dark', escape: false);
    });
});

describe('search without a tab', function () {
    it('puts the search bar at the top of Home', function () {
        // Search gave its tab up for Pick'em; the bar on Home is where a phone
        // searches now, expanding in place so the keyboard stays raised.
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Teams, players, coaches, games');
    });

    it('finds teams, players and conferences from the Home panel', function () {
        Athlete::create(['id' => 1, 'display_name' => 'George Player']);

        Livewire::test('search-panel')
            ->set('q', 'Georg')
            ->assertSee('Georgia Bulldogs')
            ->assertSee('George Player');
    });

    it('keeps /search alive for deep links and shared URLs', function () {
        Athlete::create(['id' => 1, 'display_name' => 'George Player']);

        $this->get(route('search', ['q' => 'Georg']))->assertOk();

        Livewire::test('search-page')
            ->set('q', 'Georg')
            ->assertSee('Georgia Bulldogs')
            ->assertSee('George Player');
    });

    it('shares its results with the command palette', function () {
        // Panel, page and palette all read the same index, so none can drift.
        Athlete::create(['id' => 2, 'display_name' => 'Georgina Test']);

        $panel = Livewire::test('search-panel')->set('q', 'Georg');
        $palette = Livewire::test('search')->set('q', 'Georg');

        foreach (['Georgia Bulldogs', 'Georgina Test'] as $expected) {
            $panel->assertSee($expected);
            $palette->assertSee($expected);
        }
    });

    it('refuses a query too short to be useful', function () {
        Livewire::test('search-panel')->set('q', 'G')->assertSee('Type at least two characters');
    });

    it('keeps News reachable from Home when the articles table is empty', function () {
        /*
         * With Home's section strip gone, the "More" link on the news feed is
         * the News screen's only path on a phone — so it renders even when no
         * articles exist to hang it under.
         */
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('news'), escape: false);
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

    // The rows are already stored, so the page renders them without waiting on
    // the refresh it dispatches.
    Livewire::test('player', ['athlete' => $athlete])
        ->assertOk()
        // ESPN's own headings, which beat anything we would name ourselves.
        ->assertSee('C/ATT')
        ->assertSee('25/42');
});

describe('stacking order', function () {
    /*
     * App chrome sits above anything a screen sticks to its own viewport.
     *
     * The scoreboard runs its own ladder inside the page — chrome 30, day
     * heading 20, card contents 10 — and every one of those has to stay under
     * the header and the tab bar. When the header and the day heading were
     * both z-20, the later element in the document won on tree order, which is
     * the page painting over the app.
     */
    it('keeps the header and tab bar above any screen-level sticky', function () {
        $response = $this->get(route('scoreboard'))->assertOk();

        // The bar's own bottom edge is `--viewport-bottom`, not 0 — it rides
        // the visual viewport so an iOS resume cannot strand it (CFB-53, and
        // pinned properly in PwaTest). What this test holds is the z-40.
        $response->assertSee('sticky top-0 z-40', escape: false)
            ->assertSee('fixed inset-x-0 bottom-[var(--viewport-bottom,0px)] z-40', escape: false);
    });
});
