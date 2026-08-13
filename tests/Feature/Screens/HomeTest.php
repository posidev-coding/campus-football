<?php

use App\Actions\GrantWalletEntry;
use App\Actions\ReorderFollowedTeams;
use App\Enums\ContentRating;
use App\Jobs\SyncTeamNews;
use App\Models\Article;
use App\Models\Conference;
use App\Models\Game;
use App\Models\GamePredictor;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\User;
use App\Models\Week;
use App\Support\Brand;
use App\Support\TeamGlance;
use App\Support\Voice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->season = Season::factory()->create([
        'year' => 2025, 'type' => Season::REGULAR,
        'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
    ]);

    $this->tennessee = Team::factory()->create([
        'id' => 2633, 'slug' => 'tennessee-volunteers', 'location' => 'Tennessee',
        'display_name' => 'Tennessee Volunteers', 'short_display_name' => 'Tennessee',
    ]);
    $this->kentucky = Team::factory()->create([
        'id' => 96, 'slug' => 'kentucky-wildcats', 'location' => 'Kentucky',
        'display_name' => 'Kentucky Wildcats', 'short_display_name' => 'Kentucky',
    ]);

    $this->user = User::factory()->create();
    // Attached with explicit positions: order is the model now.
    $this->user->followedTeams()->attach([2633 => ['position' => 1], 96 => ['position' => 2]]);
});

describe('the team swiper', function () {
    it('brands the card header without seating the logo on the accent', function () {
        $this->tennessee->update(['color' => 'FF8200', 'alt_color' => 'FFFFFF']);

        $this->actingAs($this->user)->get(route('home'))
            ->assertOk()
            /*
             * Gradient surface, neutral logo puck in light mode (gone in
             * dark), and Tennessee's actual branding: WHITE on orange —
             * 2.49:1, below every WCAG bar and on every jersey — rather than
             * a "correct" near-black nobody would recognize. Flat: the
             * text-shadow this band once carried is gone, and asserting its
             * ABSENCE is what stops it creeping back in.
             */
            ->assertSee('team-accent', escape: false)
            ->assertSee('bg-white shadow-sm ring-1 ring-black/10 dark:bg-transparent', escape: false)
            ->assertSee('--team-accent-contrast: #ffffff', escape: false)
            ->assertDontSee('team-text-shadow', escape: false)
            // Flat: the header's gradient read as a shadow falling across it,
            // so there is no second surface color to set.
            ->assertDontSee('--team-accent-far', escape: false)
            ->assertSee('--team-keyline: #FFFFFF', escape: false);
    });

    it('renders one card per followed team, pinned favorite first', function () {
        $html = $this->actingAs($this->user)->get(route('home'))->assertOk()->content();

        expect(substr_count($html, 'wire:key="glance-'))->toBe(2)
            // The favorite leads regardless of follow order or alphabet.
            ->and(strpos($html, 'wire:key="glance-2633"'))->toBeLessThan(strpos($html, 'wire:key="glance-96"'));
    });

    it('accepts the swipe anywhere in the section, and keeps the news a follower', function () {
        /*
         * The gesture layer: touch handlers on the SECTION so the dots row
         * and the news panels swipe too, a guard so touches beginning on the
         * card track stay native (it already swipes; a second handler would
         * double-advance), and the news track still overflow-hidden — the
         * follower design is load-bearing, because two scrollers can
         * disagree about which team is showing. No test runner delivers
         * touch events, so the bindings and the guard are what a feature
         * test can hold; the gesture itself is a real-device check.
         */
        $html = $this->actingAs($this->user)->get(route('home'))->assertOk()->content();

        expect($html)->toContain('@touchstart.passive="beginSwipe($event)"')
            ->and($html)->toContain('@touchend.passive="endSwipe($event)"')
            ->and($html)->toContain("closest('[x-ref=track]')")
            ->and($html)->toContain('overflow-hidden px-4 transition-[height]');
    });

    it('shows the most recent completed game as the last result, and no trend pills', function () {
        /*
         * The card carried a row of W/L pills from the same games. They are
         * gone deliberately rather than incidentally, which is what this
         * asserts: a scope bug had emptied them, and fixing the scope alone
         * would have brought them back by themselves once a season kicks off.
         */
        foreach ([
            ['2025-09-06', 30, 10],
            ['2025-09-13', 24, 21],
            ['2025-09-20', 13, 27],
        ] as [$day, $ours, $theirs]) {
            Game::factory()->finished($ours, $theirs)->create([
                'season_id' => $this->season->id,
                'home_team_id' => 2633, 'away_team_id' => 96,
                'kickoff_at' => $day.' 19:30:00',
            ]);
        }

        $html = $this->actingAs($this->user)->get(route('home'))->assertOk()->content();

        // The NEWEST completed game, not the oldest — the row says "last".
        expect($html)->toContain('L 13-27')
            ->and($html)->not->toContain('wire:key="trend-2633-');
    });

    it('keeps the last result when the season being played has no games yet', function () {
        /*
         * The offseason regression this pair exists for. The glance maps moved
         * to the season being PLAYED — so the header can say SEC rather than
         * last year's conference — and this query followed them, which emptied
         * it: a season nobody has kicked off contains nothing completed. Last
         * results stay on `resultsYear()`, and the row names the season so a
         * 0-0 record above a loss does not read as a contradiction.
         */
        $upcoming = Season::factory()->create([
            'year' => 2026, 'type' => Season::REGULAR,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(4)->toDateString(),
        ]);

        TeamSeason::create(['team_id' => 2633, 'season_year' => 2026, 'classification' => 'FBS']);
        TeamSeason::create(['team_id' => 96, 'season_year' => 2026, 'classification' => 'FBS']);

        /*
         * 2026 needs a SCHEDULE for the two years to differ at all —
         * `scoreboardYear()` falls back to `resultsYear()` for a season with
         * no games, which collapses the very distinction under test.
         */
        Game::factory()->create([
            'season_id' => $upcoming->id,
            'home_team_id' => 2633, 'away_team_id' => 96,
            'completed' => false, 'status' => 'pre',
            'kickoff_at' => now()->addMonth()->toDateTimeString(),
        ]);

        // Last season's bowl — played in January, belonging to 2025.
        Game::factory()->finished(21, 42)->create([
            'season_id' => $this->season->id,
            'home_team_id' => 2633, 'away_team_id' => 96,
            'kickoff_at' => '2026-01-09 19:30:00',
        ]);

        TeamGlance::flush();

        $this->actingAs($this->user)->get(route('home'))
            ->assertOk()
            ->assertSee('L 21-42')
            // Labelled with the SEASON, which the date cannot give: that game
            // was played in 2026 and belongs to 2025.
            ->assertSee('2025');
    });

    it('shows the next game when none is live', function () {
        Game::factory()->create([
            'season_id' => $this->season->id,
            'home_team_id' => 96, 'away_team_id' => 2633,
            'kickoff_at' => now()->addDays(3)->setTime(19, 30),
            'completed' => false,
        ]);

        $this->actingAs($this->user)->get(route('home'))
            ->assertOk()
            ->assertSee('Next')
            ->assertSee('at Kentucky');
    });

    it('shows a live game above everything and polls while one runs', function () {
        Game::factory()->create([
            'season_id' => $this->season->id,
            'home_team_id' => 2633, 'away_team_id' => 96,
            'kickoff_at' => now()->subHour(),
            'completed' => false, 'status' => 'in', 'status_detail' => '3rd 8:42',
            'home_score' => 21, 'away_score' => 14,
        ]);

        $this->actingAs($this->user)->get(route('home'))
            ->assertOk()
            ->assertSee('LIVE')
            ->assertSee('21')
            ->assertSee('14')
            ->assertSee('wire:poll.30s.visible', escape: false);
    });

    describe('the live glance', function () {
        beforeEach(function () {
            $this->liveGame = Game::factory()->create([
                'season_id' => $this->season->id,
                'home_team_id' => 2633, 'away_team_id' => 96,
                'kickoff_at' => now()->subHour(),
                'completed' => false, 'status' => 'in',
                'home_score' => 24, 'away_score' => 21,
                'period' => 3, 'clock' => '8:42',
                'possession_team_id' => 2633,
                'down_distance_text' => '3rd & 7 at KY 42',
                'last_play_text' => 'Stockton pass complete to Sampson for 12 yards',
            ]);
        });

        it('replaces both the next game and the last result', function () {
            // Three rows is a list, not a glance — while a team is playing,
            // the next fixture and last week's score are both the wrong
            // answer to "what is happening".
            Game::factory()->create([
                'season_id' => $this->season->id,
                'home_team_id' => 96, 'away_team_id' => 2633,
                'kickoff_at' => now()->addDays(7), 'completed' => false,
            ]);

            Game::factory()->finished(31, 17)->create([
                'season_id' => $this->season->id,
                'home_team_id' => 2633, 'away_team_id' => 96,
                'kickoff_at' => now()->subWeek(),
            ]);

            $this->actingAs($this->user)->get(route('home'))
                ->assertOk()
                ->assertSee('LIVE')
                ->assertDontSee('>Next<', escape: false)
                ->assertDontSee('>Last<', escape: false);
        });

        it('carries the situation, and composes the clock from period', function () {
            // "3rd · 8:42" is built by Game::liveStatusLine(); status_detail is
            // deliberately absent on this fixture so the fallback cannot be
            // what satisfies the assertion.
            $this->actingAs($this->user)->get(route('home'))
                ->assertOk()
                ->assertSee('3rd · 8:42')
                ->assertSee('3rd &amp; 7 at KY 42', escape: false)
                ->assertSee('Stockton pass complete')
                ->assertSee('Has possession');
        });

        it('renders with scores alone when ESPN sends no situation', function () {
            /*
             * A live payload omitting the situation block is a transient gap,
             * not an absence — the sync leaves those columns alone rather than
             * nulling real data over a momentary silence, so the card has to
             * read correctly with any subset of them.
             */
            // `period` is NOT NULL and carries 0 for "no period" — which is
            // what a halftime row actually holds, and what periodLabel()'s
            // falsy guard is written against.
            $this->liveGame->update([
                'down_distance_text' => null,
                'last_play_text' => null,
                'possession_team_id' => null,
                'clock' => null,
                'period' => 0,
                'status_detail' => 'Halftime',
            ]);

            $this->actingAs($this->user)->get(route('home'))
                ->assertOk()
                ->assertSee('LIVE')
                ->assertSee('Halftime')
                ->assertSee('24')
                ->assertDontSee('Has possession');
        });

        it('links the block to the game while the header still links to the team', function () {
            // The card cannot be one link — anchors do not nest — so the two
            // destinations live on separate elements and both must survive.
            $html = $this->actingAs($this->user)->get(route('home'))->assertOk()->content();

            expect($html)->toContain(route('game', $this->liveGame))
                ->and($html)->toContain(route('team', $this->tennessee));
        });
    });

    it('does not poll when nothing is live', function () {
        $this->actingAs($this->user)->get(route('home'))
            ->assertOk()
            ->assertDontSee('wire:poll', escape: false);
    });

    it("renders every followed team's news up front, toggled client-side", function () {
        $volsStory = Article::create(['espn_id' => 1, 'headline' => 'Tennessee lands a commit', 'published_at' => now()]);
        $volsStory->teams()->attach(2633);
        $catsStory = Article::create(['espn_id' => 2, 'headline' => 'Kentucky names a starter', 'published_at' => now()]);
        $catsStory->teams()->attach(96);

        // Both lists are in the DOM — swiping must never wait on a round trip
        // — with only the active one visible.
        $this->actingAs($this->user)->get(route('home'))
            ->assertOk()
            ->assertSee('Tennessee lands a commit')
            ->assertSee('Kentucky names a starter')
            ->assertSee('wire:key="team-news-2633"', escape: false)
            ->assertSee('wire:key="team-news-96"', escape: false);
    });

    it('writes the record line against the conference it names', function () {
        /*
         * The glance card builds "8-4 (4-4) · 2nd in SEC" from the same two
         * sources the team hero does, and had the same disagreement: the name
         * from `team_seasons`, the position from `standings`, where ESPN files
         * every team again under the 138-team "FBS" division group.
         *
         * This holds the CARD to the conference number while both rows exist.
         * Which row wins was a last-group-wins race decided in TeamGlance, so
         * the deterministic reproduction lives in StandingPositionsTest; here
         * it would land either way run to run.
         */
        Conference::factory()->create([
            'id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC', 'abbreviation' => 'sec',
        ]);
        Conference::factory()->create([
            'id' => 80, 'name' => 'NCAA Division I FBS', 'short_name' => 'FBS',
            'abbreviation' => 'fbs', 'is_conference' => false,
        ]);

        // A completed game is what makes 2025 the results year these maps read.
        Game::factory()->finished(24, 17)->create([
            'season_id' => $this->season->id,
            'home_team_id' => 2633, 'away_team_id' => 96,
            'kickoff_at' => '2025-09-06 19:30:00',
        ]);

        foreach ([[2633, 8, 4], [96, 11, 7]] as [$id, $wins, $confWins]) {
            TeamSeason::create(['team_id' => $id, 'season_year' => 2025, 'conference_id' => 8, 'classification' => 'FBS']);

            foreach ([8, 80] as $group) {
                Standing::create([
                    'season_year' => 2025, 'conference_id' => $group, 'team_id' => $id, 'source' => 'espn',
                    'overall_wins' => $wins, 'overall_losses' => 12 - $wins,
                    'conf_wins' => $confWins, 'conf_losses' => 8 - $confWins,
                    'win_pct' => round($wins / 12, 4), 'conf_win_pct' => round($confWins / 8, 4),
                ]);
            }
        }

        $this->actingAs($this->user)->get(route('home'))
            ->assertOk()
            ->assertSee('8-4 (4-4) · 2nd in SEC')
            ->assertSee('11-1 (7-1) · 1st in SEC');
    });

    it('drops the position from the record line before a season kicks off', function () {
        /*
         * Deterministic where the one above is not: an all-0-0 conference used
         * to hand every card a position, so a card could read "1st in SEC" in
         * February. The record stays — it is a fact — and so does the
         * conference.
         */
        Conference::factory()->create([
            'id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC', 'abbreviation' => 'sec',
        ]);

        Game::factory()->finished(24, 17)->create([
            'season_id' => $this->season->id,
            'home_team_id' => 2633, 'away_team_id' => 96,
            'kickoff_at' => '2025-09-06 19:30:00',
        ]);

        foreach ([2633, 96] as $id) {
            TeamSeason::create(['team_id' => $id, 'season_year' => 2025, 'conference_id' => 8, 'classification' => 'FBS']);
            Standing::create(['season_year' => 2025, 'conference_id' => 8, 'team_id' => $id, 'source' => 'espn']);
        }

        $this->actingAs($this->user)->get(route('home'))
            ->assertOk()
            ->assertSee('0-0 (0-0) · SEC')
            ->assertDontSee(' in SEC');
    });

    it('issues the same number of queries for one followed team as for five', function () {
        $one = User::factory()->create();
        $one->followedTeams()->attach(2633);

        $five = User::factory()->create();
        $five->followedTeams()->attach(
            collect([$this->tennessee, $this->kentucky])
                ->merge(Team::factory()->count(3)->create())
                ->pluck('id')
        );

        $countFor = function (User $user) {
            Cache::flush();
            TeamGlance::flush();
            // Both measurements have to start from the same cold state.
            // Cache::flush() alone does not do it for anything memoized in a
            // STATIC property on top of the cache — the second run would skip
            // the lookup the first one paid for and read one query cheaper,
            // which looks exactly like the regression this test is for.
            Brand::flush();
            DB::enableQueryLog();
            DB::flushQueryLog();

            $this->actingAs($user)->get(route('home'))->assertOk();

            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            auth()->logout();

            return $queries;
        };

        expect($countFor($five))->toBe($countFor($one));
    });
});

describe('without teams', function () {
    it('is a working page, not an empty state', function () {
        $newcomer = User::factory()->create();
        Article::create(['espn_id' => 3, 'headline' => 'A national story', 'published_at' => now()]);

        $this->actingAs($newcomer)->get(route('home'))
            ->assertOk()
            // The swiper renders even at zero follows: Bandwagon State holds
            // the first slot, the add card holds the second, and the whole
            // in-place onboarding stays on the page they are looking at.
            ->assertSee('Bandwagon State')
            ->assertSee('Add your team')
            ->assertSee('Search FBS teams')
            // …and the page still carries content underneath it.
            ->assertSee('A national story')
            ->assertSee('Latest news');
    });

    it('seats Bandwagon State in the swiper with its news panel aligned', function () {
        /*
         * The two tracks are mapped index for index, so the placeholder
         * needs BOTH a card and a news panel or every later panel sits one
         * behind its card for the length of the swipe. The add slot must
         * still count from follows — the placeholder is not a team, and the
         * copy would otherwise claim four slots when all five are free.
         */
        $html = $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('0-99 (0-99)', escape: false)
            ->assertSee('Couch Conference')
            ->content();

        expect($html)->toContain('wire:key="glance-placeholder"')
            ->and($html)->toContain('wire:key="team-news-placeholder"')
            ->and($html)->toContain('data-tour="glance"');
    });

    it('renders the placeholder from constants alone, no tables required', function () {
        /*
         * The zero-query invariant, proven the blunt way: no seasons, no
         * standings, no team_seasons rows exist for it to read. A
         * placeholder that touched a map or a game query would 500 or
         * render empty here.
         */
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Bandwagon State');
    });

    it('speaks the placeholder in each register, escalating', function () {
        foreach (['placeholder.body', 'placeholder.news'] as $key) {
            $pg = Voice::line($key, ['name' => 'Bandwagon State'], for: User::factory()->make(['content_rating' => ContentRating::Pg]));
            $r = Voice::line($key, ['name' => 'Bandwagon State'], for: User::factory()->make(['content_rating' => ContentRating::R]));

            expect($pg)->not->toBe('')
                ->and($r)->not->toBe('')
                ->and($r)->not->toBe($pg);
        }
    });
});

describe('the placeholder never leaks', function () {
    it('vanishes the moment a real team is followed', function () {
        // The 2-follow fixture must never see the joke — the swiper pin of
        // exactly two glance keys lives in the swiper suite above.
        $this->actingAs($this->user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Bandwagon State');
    });

    it('never renders for a guest', function () {
        // A guest gets the front door, not a fake balance sheet.
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Bandwagon State')
            ->assertDontSee('glance-placeholder');
    });
});

describe('the pick'."'".'em teaser', function () {
    it('renders as a designed card that opens the Picks screen', function () {
        // Inert until the Picks screen existed; now the whole card navigates.
        $this->actingAs($this->user)->get(route('home'))
            ->assertOk()
            // escape: false — the label is literal template text, not an
            // escaped Blade echo, so the raw apostrophe is what is in the DOM.
            ->assertSee("Pick'em", escape: false)
            ->assertSee('Coming soon')
            ->assertSee(route('picks'), escape: false);
    });

    it('shows guests the same promise', function () {
        $this->get(route('home'))->assertOk()
            ->assertSee('Coming soon')
            ->assertSee(route('picks'), escape: false);
    });
});

describe('the wallet chips', function () {
    it('puts the gamification placeholders on both surfaces for a signed-in reader', function () {
        // Home's brand bar below `sm`, the layout header above — one key,
        // whichever is visible. Zero-state literals until the gamification
        // schema exists; both chips open the Picks screen, which says
        // "Coming soon" so the zeros never pretend the feature is live.
        $html = $this->actingAs($this->user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Rookie')
            ->assertSee('0 XP')
            ->assertSee('Beast Lattes', escape: false)
            ->content();

        expect(substr_count($html, 'data-tour="wallet"'))->toBeGreaterThanOrEqual(2);
    });

    it('shows a guest no balance at all', function () {
        // A balance is YOURS — a guest header keeps sign-in instead.
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-tour="wallet"', escape: false)
            ->assertDontSee('Rookie');
    });

    it('references currency art that actually exists on disk', function () {
        // The chips render <img> tags; a renamed asset 404s as a silently
        // blank icon rather than an error anywhere a test would look.
        $source = file_get_contents(resource_path('views/components/wallet-chips.blade.php'));

        preg_match_all('/brand\/currency\/[a-z0-9\/.-]+/', $source, $paths);

        expect($paths[0])->not->toBe([]);

        foreach (array_unique($paths[0]) as $path) {
            expect(file_exists(public_path($path)))->toBeTrue("Missing currency asset: {$path}");
        }
    });
});

describe('the featured games', function () {
    beforeEach(function () {
        $this->upcoming = Season::factory()->create([
            'year' => 2026, 'type' => Season::REGULAR,
            'start_date' => '2026-08-29', 'end_date' => '2026-12-12',
        ]);
        $this->week1 = Week::create([
            'season_id' => $this->upcoming->id, 'number' => 1, 'name' => 'Week 1',
            'start_date' => '2026-08-29', 'end_date' => '2026-09-07',
        ]);
    });

    it('leads with the season being played, not last season\'s bowls', function () {
        /*
         * `resultsYear()` stays on the last season PLAYED, so all summer this
         * section served finished bowl games under a "Top 25" heading. The
         * same trap the team page's schedule fell into.
         */
        /*
         * Dates pinned, not left to the factory. `CfbCalendar` resolves the
         * current season from date RANGES and never from the `year` column, so
         * a 2025 row carrying some other year's dates becomes "the season we
         * are heading into" and pulls the whole page back a season. The factory
         * derives these correctly now; pinning them here says which dates this
         * test actually depends on.
         */
        $bowlSeason = Season::factory()->create([
            'year' => 2025, 'type' => Season::POSTSEASON,
            'start_date' => '2025-12-13', 'end_date' => '2026-01-21',
        ]);
        $bowlWeek = Week::create([
            'season_id' => $bowlSeason->id, 'number' => 1, 'name' => 'Bowls',
            'start_date' => '2025-12-13', 'end_date' => '2026-01-21',
        ]);
        Game::factory()->finished()->create([
            'season_id' => $bowlSeason->id, 'week_id' => $bowlWeek->id,
            'home_team_id' => 2633, 'away_team_id' => 96,
            'name' => 'Stale Bowl Game', 'kickoff_at' => '2025-12-30 20:00:00',
        ]);

        $opener = Game::factory()->create([
            'season_id' => $this->upcoming->id, 'week_id' => $this->week1->id,
            'home_team_id' => 2633, 'away_team_id' => 96,
            'kickoff_at' => '2026-09-05 19:30:00', 'completed' => false,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('game', $opener), escape: false)
            ->assertDontSee('Stale Bowl Game');
    });

    it('does not call six openers the Top 25 when no poll exists yet', function () {
        // Scope::teamIds falls back to all of FBS without a poll, so the
        // heading has to say what these games actually are.
        Game::factory()->create([
            'season_id' => $this->upcoming->id, 'week_id' => $this->week1->id,
            'home_team_id' => 2633, 'away_team_id' => 96,
            'kickoff_at' => '2026-09-05 19:30:00', 'completed' => false,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Best of Week 1')
            ->assertDontSee('Top 25 games');
    });

    it('dates the featured games, which span a whole week with no day headings', function () {
        Game::factory()->create([
            'season_id' => $this->upcoming->id, 'week_id' => $this->week1->id,
            'home_team_id' => 2633, 'away_team_id' => 96,
            'kickoff_at' => '2026-09-06 00:30:00', 'completed' => false,
        ]);

        // 00:30 UTC is still the 5th in ET.
        $this->get(route('home'))->assertOk()->assertSee('9/5');
    });

    it('ranks by projected matchup quality when there is no poll', function () {
        $dull = Game::factory()->create([
            'season_id' => $this->upcoming->id, 'week_id' => $this->week1->id,
            'home_team_id' => 2633, 'away_team_id' => null,
            'kickoff_at' => '2026-09-05 12:00:00', 'completed' => false,
        ]);
        $marquee = Game::factory()->create([
            'season_id' => $this->upcoming->id, 'week_id' => $this->week1->id,
            'home_team_id' => 96, 'away_team_id' => null,
            'kickoff_at' => '2026-09-05 20:00:00', 'completed' => false,
        ]);

        GamePredictor::create(['game_id' => $dull->id, 'matchup_quality' => 20.5]);
        GamePredictor::create(['game_id' => $marquee->id, 'matchup_quality' => 88.0]);

        // Kicks off LATER but is the better game, so it leads.
        $this->get(route('home'))
            ->assertOk()
            ->assertSeeInOrder([route('game', $marquee), route('game', $dull)], escape: false);
    });
});

describe('quick add', function () {
    beforeEach(function () {
        $this->newcomer = User::factory()->create();

        // The picker is FBS-for-this-season, so membership rows are what make
        // a team offerable at all.
        foreach ([2633, 96] as $id) {
            TeamSeason::create([
                'team_id' => $id, 'season_year' => 2026, 'classification' => 'FBS',
            ]);
        }
    });

    it('puts the first team added at the top of the list', function () {
        // Being first IS what "favorite" used to mean, so there is no separate
        // decision to make and nothing to set afterwards.
        Queue::fake();

        Livewire::actingAs($this->newcomer)
            ->test('home')
            ->set('teamQuery', 'Tennessee')
            ->call('addTeam', 2633);

        expect($this->newcomer->followedTeams()->pluck('teams.id')->all())->toBe([2633])
            ->and($this->newcomer->followedTeams()->first()->pivot->position)->toBe(1);
    });

    it('appends a second team below the first rather than displacing it', function () {
        Queue::fake();

        Livewire::actingAs($this->newcomer)->test('home')->call('addTeam', 2633);
        Livewire::actingAs($this->newcomer)->test('home')->call('addTeam', 96);

        expect($this->newcomer->followedTeams()->pluck('teams.id')->all())->toBe([2633, 96]);
    });

    it('clears the query and shows the new team as a card', function () {
        Queue::fake();

        Livewire::actingAs($this->newcomer)
            ->test('home')
            ->set('teamQuery', 'Tenn')
            ->call('addTeam', 2633)
            ->assertSet('teamQuery', '')
            ->assertSee('wire:key="glance-2633"', escape: false);
    });

    it('offers a slot until five teams are followed, then stops', function () {
        Queue::fake();

        $extra = Team::factory()->count(3)->create();
        foreach ($extra as $team) {
            TeamSeason::create(['team_id' => $team->id, 'season_year' => 2026, 'classification' => 'FBS']);
        }

        // Four followed: still room, so the slot is there.
        $this->user->followedTeams()->attach($extra->take(2)->pluck('id'));

        Livewire::actingAs($this->user)->test('home')->assertSee('Add another');

        // Five: the slot goes away rather than offering a follow that throws.
        $this->user->followedTeams()->attach($extra->last()->id);

        Livewire::actingAs($this->user)->test('home')
            ->assertDontSee('Add another')
            ->assertDontSee('Add your team');
    });

    it('does not offer teams already followed', function () {
        $matches = Livewire::actingAs($this->user)
            ->test('home')
            ->set('teamQuery', 'Tennessee')
            ->get('teamMatches');

        expect(collect($matches)->pluck('id'))->not->toContain(2633);
    });

    it('dispatches the news sync for a quick-added team', function () {
        // Following is what fills a team's news tab; quick add must not be a
        // back door that skips it.
        Queue::fake();

        Livewire::actingAs($this->newcomer)->test('home')->call('addTeam', 2633);

        Queue::assertPushed(SyncTeamNews::class, fn ($job) => $job->teamId === 2633);
    });
});

describe('order follows the user', function () {
    it('reorders the swipe order when the account list is reordered', function () {
        // The account list is the single source of order — Home does not sort,
        // it just renders what the pivot's position gives it.
        $before = $this->actingAs($this->user)->get(route('home'))->content();

        expect(strpos($before, 'wire:key="glance-2633"'))
            ->toBeLessThan(strpos($before, 'wire:key="glance-96"'));

        app(ReorderFollowedTeams::class)->handle($this->user, [96, 2633]);
        TeamGlance::flush();

        $after = $this->actingAs($this->user->fresh())->get(route('home'))->content();

        expect(strpos($after, 'wire:key="glance-96"'))
            ->toBeLessThan(strpos($after, 'wire:key="glance-2633"'));
    });
});

describe('the verify nudge', function () {
    it('leads the page for an unverified account, reward first and one row tall', function () {
        /*
         * Session-dismissable by design: sessionStorage dies with the browser
         * session, so the nudge returns next visit — the 14-day self-destruct
         * clock is real. The dismissal key is pinned here so a rename cannot
         * silently promote it to the install banner's forever-localStorage.
         * One sentence, one row: the nudge shrank after review because it
         * was taxing the screen it sells — the latte still leads.
         */
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Beast Latte and XP waiting on it')
            ->assertSee('Resend')
            ->assertSee(route('verification.notice'))
            ->assertSee('cfb.verify.dismissed')
            ->assertSee('sessionStorage');
    });

    it('renders nothing once verified, and nothing for a guest', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-verify-callout');

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-verify-callout');
    });

    it('speaks the nudge in every register, escalating', function () {
        foreach (['verify.callout.body', 'verify.picks.body'] as $key) {
            $pg = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::Pg]));
            $r = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::R]));

            expect($pg)->not->toBe('')
                ->and($r)->not->toBe('')
                ->and($r)->not->toBe($pg);
        }
    });
});

describe('the wallet pays', function () {
    it('shows the seed and the verification payout as real numbers', function () {
        /*
         * The chips read SUMs now, through the one write doorway
         * (GrantWalletEntry) — moment seed plus verification payout is
         * 125 XP and one latte, and the singular aria-label proves the
         * numbers flow rather than the literals surviving.
         */
        $user = User::factory()->create();
        $grant = app(GrantWalletEntry::class);

        $grant->handle($user, xp: GrantWalletEntry::FIRST_TEAM_XP, lattes: 0,
            reason: GrantWalletEntry::REASON_FIRST_TEAM, key: GrantWalletEntry::REASON_FIRST_TEAM);
        $grant->handle($user, xp: GrantWalletEntry::VERIFICATION_XP, lattes: GrantWalletEntry::VERIFICATION_LATTES,
            reason: GrantWalletEntry::REASON_EMAIL_VERIFIED, key: GrantWalletEntry::REASON_EMAIL_VERIFIED);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('125 XP')
            ->assertSee('1 Beast Latte —');
    });
});
