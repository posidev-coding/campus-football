<?php

use App\Jobs\SyncTeamNews;
use App\Models\Article;
use App\Models\Game;
use App\Models\GamePredictor;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\User;
use App\Models\Week;
use App\Support\TeamGlance;
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

    $this->user = User::factory()->create(['favorite_team_id' => 2633]);
    $this->user->followedTeams()->attach([2633, 96]);
});

describe('the team swiper', function () {
    it('brands the card header without seating the logo on the accent', function () {
        $this->tennessee->update(['color' => 'FF8200', 'alt_color' => 'FFFFFF']);

        $this->actingAs($this->user)->get(route('home'))
            ->assertOk()
            /*
             * Gradient surface, neutral logo puck in light mode (gone in
             * dark), and Tennessee's actual branding: WHITE on orange —
             * 2.49:1, below every WCAG bar and on every jersey — carried by
             * the shadow treatment rather than swapped for a "correct"
             * near-black nobody would recognize.
             */
            ->assertSee('team-gradient', escape: false)
            ->assertSee('bg-white shadow-sm ring-1 ring-black/10 dark:bg-transparent', escape: false)
            ->assertSee('--team-accent-contrast: #ffffff', escape: false)
            ->assertSee('team-text-shadow', escape: false)
            // The gradient's far end comes from PHP, so it can move away from
            // the text rather than always darkening.
            ->assertSee('--team-accent-far:', escape: false)
            ->assertSee('--team-keyline: #FFFFFF', escape: false);
    });

    it('renders one card per followed team, pinned favorite first', function () {
        $html = $this->actingAs($this->user)->get(route('home'))->assertOk()->content();

        expect(substr_count($html, 'wire:key="glance-'))->toBe(2)
            // The favorite leads regardless of follow order or alphabet.
            ->and(strpos($html, 'wire:key="glance-2633"'))->toBeLessThan(strpos($html, 'wire:key="glance-96"'));
    });

    it('derives form from our own box of completed games, oldest to newest', function () {
        // Three completed games: W, W, L in kickoff order.
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

        preg_match_all('/wire:key="form-2633-\d+"[^>]*>(\w)</', $html, $pills);

        expect($pills[1])->toBe(['W', 'W', 'L']);
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
            ->assertSee('21-14')
            ->assertSee('wire:poll.30s.visible', escape: false);
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
            // The invitation is now the swiper's own empty slot — onboarding
            // in place, rather than a callout sending them to Account to fill
            // in the page they are already looking at.
            ->assertSee('Add your team')
            ->assertSee('Search FBS teams')
            // …and the page still carries content underneath it.
            ->assertSee('A national story')
            ->assertSee('Latest news');
    });
});

describe('the pick'."'".'em teaser', function () {
    it('renders as a designed, inert card', function () {
        $this->actingAs($this->user)->get(route('home'))
            ->assertOk()
            // escape: false — the label is literal template text, not an
            // escaped Blade echo, so the raw apostrophe is what is in the DOM.
            ->assertSee("Pick'em", escape: false)
            ->assertSee('Coming soon');
    });

    it('shows guests the same promise', function () {
        $this->get(route('home'))->assertOk()->assertSee('Coming soon');
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
        $bowlSeason = Season::factory()->create(['year' => 2025, 'type' => Season::POSTSEASON]);
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

    it('makes the first team added the favorite, without a trip to Account', function () {
        /*
         * Nobody picks their one and only team and then expects it not to
         * lead the page. Making them say so twice is a second trip for a
         * decision already made.
         */
        Queue::fake();

        Livewire::actingAs($this->newcomer)
            ->test('home')
            ->set('teamQuery', 'Tennessee')
            ->call('addTeam', 2633);

        $user = $this->newcomer->fresh();

        expect($user->favorite_team_id)->toBe(2633)
            ->and($user->followedTeams()->whereKey(2633)->exists())->toBeTrue();
    });

    it('leaves the favorite alone when adding a second team', function () {
        Queue::fake();

        $this->newcomer->forceFill(['favorite_team_id' => 2633])->save();
        $this->newcomer->followedTeams()->attach(2633);

        Livewire::actingAs($this->newcomer)
            ->test('home')
            ->call('addTeam', 96);

        expect($this->newcomer->fresh()->favorite_team_id)->toBe(2633);
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
