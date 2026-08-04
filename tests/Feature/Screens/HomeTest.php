<?php

use App\Models\Article;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamGlance;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
            // The invitation, in the user's register…
            ->assertSee('Follow a team')
            ->assertSee(route('account'), escape: false)
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
