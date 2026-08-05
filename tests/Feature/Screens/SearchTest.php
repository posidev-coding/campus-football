<?php

use App\Models\Athlete;
use App\Models\AthleteTeamSeason;
use App\Models\Coach;
use App\Models\CoachTeamSeason;
use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Models\Game;
use App\Models\Position;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\Week;
use App\Support\Search;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->season = Season::factory()->create([
        'year' => 2025, 'type' => Season::REGULAR,
        'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
    ]);

    Conference::factory()->create(['id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC', 'is_conference' => true]);

    $this->georgia = Team::factory()->create([
        'id' => 61, 'slug' => 'georgia-bulldogs', 'location' => 'Georgia',
        'display_name' => 'Georgia Bulldogs', 'nickname' => 'Bulldogs', 'abbreviation' => 'UGA',
    ]);
    TeamSeason::create(['team_id' => 61, 'season_year' => 2025, 'conference_id' => 8, 'classification' => 'FBS']);
});

describe('teams', function () {
    it('finds a team by nickname, not only by prefix', function () {
        // "bulldogs" must find Georgia even though the display name starts
        // with "Georgia" — contains-matching is the point of the strategy.
        expect(Search::teams('bulldogs')->pluck('id'))->toContain(61);
    });

    it('puts FBS teams above everyone else', function () {
        $fcs = Team::factory()->create(['location' => 'Georgia Southern FCS', 'display_name' => 'Georgia Southern FCS Eagles']);
        TeamSeason::create(['team_id' => $fcs->id, 'season_year' => 2025, 'conference_id' => 8, 'classification' => 'FCS']);

        $ids = Search::teams('Georgia')->pluck('id');

        expect($ids->first())->toBe(61);
    });

    it('renders the rank beside the name and NOT in the subtext', function () {
        $week = Week::create([
            'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
            'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
        ]);
        Ranking::create([
            'season_id' => $this->season->id, 'week_id' => $week->id,
            'poll' => 'ap', 'team_id' => 61, 'rank' => 3,
        ]);
        Standing::create([
            'season_year' => 2025, 'conference_id' => 8, 'team_id' => 61, 'source' => 'espn',
            'overall_wins' => 11, 'overall_losses' => 2, 'overall_ties' => 0,
            'conf_wins' => 7, 'conf_losses' => 1, 'conf_ties' => 0,
        ]);

        $html = Livewire::test('search-page')->set('q', 'Georgia')->html();

        // The numeral immediately precedes the name in its own muted span;
        // the subtext carries conference, record and standing position.
        expect($html)->toContain('11-2 (7-1)')
            ->toContain('1st in SEC');

        $rankPos = strpos($html, '>3</span>');
        $namePos = strpos($html, 'Georgia Bulldogs</span>');

        expect($rankPos)->not->toBeFalse()
            ->and($namePos)->not->toBeFalse()
            ->and($rankPos)->toBeLessThan($namePos);
    });
});

describe('players', function () {
    it('ranks active players above those who left', function () {
        $gone = Athlete::create(['id' => 1, 'display_name' => 'Marcus Tester', 'last_name' => 'Tester', 'is_active' => false]);
        $current = Athlete::create(['id' => 2, 'display_name' => 'Marcus Testerson', 'last_name' => 'Testerson', 'is_active' => true]);

        AthleteTeamSeason::create(['athlete_id' => 1, 'team_id' => 61, 'season_year' => 2022]);
        AthleteTeamSeason::create(['athlete_id' => 2, 'team_id' => 61, 'season_year' => 2025]);

        expect(Search::players('Marcus')->pluck('id')->all())->toBe([2, 1]);
    });

    it('matches by last name prefix', function () {
        Athlete::create(['id' => 3, 'display_name' => 'Gunner Stockton', 'last_name' => 'Stockton', 'is_active' => true]);

        expect(Search::players('Stock')->pluck('id'))->toContain(3);
    });

    it('renders jersey, position, class, team and hometown on its own line', function () {
        $position = Position::create(['id' => 1, 'name' => 'Quarterback', 'abbreviation' => 'QB']);
        Athlete::create([
            'id' => 4, 'display_name' => 'Gunner Stockton', 'last_name' => 'Stockton',
            'is_active' => true, 'birth_city' => 'Tiger', 'birth_state' => 'GA',
        ]);
        AthleteTeamSeason::create([
            'athlete_id' => 4, 'team_id' => 61, 'season_year' => 2025,
            'jersey' => '14', 'position_id' => 1, 'experience_class' => 'Junior',
        ]);

        Livewire::test('search-page')
            ->set('q', 'Stockton')
            ->assertSee('#14 · QB · Junior · Georgia')
            ->assertSee('Tiger, GA');
    });

    it('renders a player with no hometown and no roster row without an empty line', function () {
        Athlete::create(['id' => 5, 'display_name' => 'Mystery Marcus', 'last_name' => 'Marcus', 'is_active' => false]);

        Livewire::test('search-page')
            ->set('q', 'Mystery')
            ->assertOk()
            ->assertSee('Mystery Marcus');
    });
});

describe('coaches', function () {
    it('finds a coach and shows their current school', function () {
        Coach::create(['id' => 1, 'first_name' => 'Kirby', 'last_name' => 'Smart', 'display_name' => 'Kirby Smart']);
        CoachTeamSeason::create(['coach_id' => 1, 'team_id' => 61, 'season_year' => 2025]);

        Livewire::test('search-page')
            ->set('q', 'Smart')
            ->assertSee('Kirby Smart')
            ->assertSee('Head Coach · Georgia');
    });

    it('ranks the current coach above a historical one', function () {
        Coach::create(['id' => 1, 'display_name' => 'Terry Coachman', 'last_name' => 'Coachman']);
        Coach::create(['id' => 2, 'display_name' => 'Tammy Coachman', 'last_name' => 'Coachman']);
        CoachTeamSeason::create(['coach_id' => 1, 'team_id' => 61, 'season_year' => 2021]);
        CoachTeamSeason::create(['coach_id' => 2, 'team_id' => 61, 'season_year' => 2025]);

        expect(Search::coaches('Coachman')->pluck('id')->all())->toBe([2, 1]);
    });

    it('renders a coach page with tenures newest first', function () {
        $coach = Coach::create(['id' => 3, 'display_name' => 'Kirby Smart', 'last_name' => 'Smart']);
        CoachTeamSeason::create(['coach_id' => 3, 'team_id' => 61, 'season_year' => 2024]);
        CoachTeamSeason::create(['coach_id' => 3, 'team_id' => 61, 'season_year' => 2025]);

        $html = $this->get(route('coach', $coach))->assertOk()->content();

        expect(strpos($html, '2025'))->toBeLessThan(strpos($html, '2024'));
    });
});

describe('conferences', function () {
    it('puts real conferences above ESPN groupings', function () {
        Conference::factory()->create(['id' => 90, 'name' => 'SEC Division Thing', 'short_name' => 'SECD', 'is_conference' => false]);

        expect(Search::conferences('SEC')->pluck('id')->first())->toBe(8);
    });

    it('shows the member count and classification', function () {
        ConferenceSeason::create(['conference_id' => 8, 'season_year' => 2025, 'classification' => 'FBS']);

        Livewire::test('search-page')
            ->set('q', 'Southeastern')
            ->assertSee('1 team · FBS');
    });
});

describe('games', function () {
    it('finds a game by either team in its name', function () {
        Game::factory()->create([
            'season_id' => $this->season->id,
            'name' => 'Alabama Crimson Tide at Georgia Bulldogs',
            'kickoff_at' => '2025-09-27 19:30:00',
        ]);

        // "Alabama" is the AWAY team — a prefix match could never find it.
        expect(Search::games('Alabama'))->toHaveCount(1);
    });

    it('finds a bowl by its real name in the note', function () {
        Game::factory()->create([
            'season_id' => $this->season->id,
            'name' => 'TBD at TBD',
            'home_team_id' => null, 'away_team_id' => null,
            'note' => 'Rose Bowl Presented by Prudential',
            'kickoff_at' => '2026-01-01 17:00:00',
        ]);

        expect(Search::games('Rose Bowl'))->toHaveCount(1);
    });

    it('puts a live game above a finished one', function () {
        Game::factory()->finished()->create([
            'season_id' => $this->season->id,
            'name' => 'Testville at Testburg', 'kickoff_at' => now()->subWeek(),
        ]);
        $live = Game::factory()->create([
            'season_id' => $this->season->id,
            'name' => 'Testburg at Testville', 'kickoff_at' => now()->subHour(),
            'status' => 'in', 'completed' => false,
        ]);

        expect(Search::games('Testville')->first()->id)->toBe($live->id);
    });

    it('puts an upcoming game above an old one', function () {
        $old = Game::factory()->finished()->create([
            'season_id' => $this->season->id,
            'name' => 'Testville at Testburg', 'kickoff_at' => now()->subMonths(2),
        ]);
        $next = Game::factory()->create([
            'season_id' => $this->season->id,
            'name' => 'Testburg at Testville', 'kickoff_at' => now()->addDays(3),
            'completed' => false,
        ]);

        expect(Search::games('Testville')->pluck('id')->all())->toBe([$next->id, $old->id]);
    });
});

describe('the shared surfaces', function () {
    it('restores a deep-linked search from the URL', function () {
        $this->get(route('search', ['q' => 'Georgia']))
            ->assertOk()
            ->assertSee('Georgia Bulldogs');
    });

    it('refuses a query too short to be useful', function () {
        Livewire::test('search-panel')->set('q', 'G')->assertSee('Type at least two characters');
    });

    it('never calls ESPN', function () {
        Http::fake();

        Livewire::test('search-panel')->set('q', 'Georgia')->assertOk();
        Livewire::test('search-page')->set('q', 'Georgia')->assertOk();
        Livewire::test('search')->set('q', 'Georgia')->assertOk();

        Http::assertNothingSent();
    });

    it('gives the phone the same header the desktop has', function () {
        /*
         * Sticky rather than a row that scrolls away, and dressed as chrome
         * rather than as content. Three things it has to get right:
         *
         *   - THE SAME SURFACE AS THE LAYOUT HEADER. Below `sm` that header is
         *     hidden and this bar is what a phone has instead; a different
         *     rule or a different tint would read as a second piece of chrome.
         *   - NOTHING TO TRAVEL THROUGH. The container's `px-4 py-5` is
         *     cancelled and re-applied inside, or the bar drifts up on the
         *     first scroll while it closes that gap.
         *   - z-30 is screen chrome: above cards (z-10), below the tab bar
         *     (z-40), which must always cover it. The translucency is only
         *     safe because that order is right.
         *
         * Asserted as one literal class list rather than as separate contains:
         * Flux's own input markup carries translucent surfaces, so a whole-tree
         * search for `bg-white/` finds those instead of this bar.
         */
        $bar = 'class="sticky top-0 z-30 -mx-4 -mt-5 -mb-3 border-b border-zinc-200 bg-white/85 px-4 pt-5 pb-3 backdrop-blur sm:hidden dark:border-zinc-800 dark:bg-zinc-950/85"';

        expect(Livewire::test('search-panel')->html())->toContain($bar);

        // The rule and the surface are the layout header's own, verbatim. If
        // the header restyles, this fails rather than quietly drifting apart.
        $header = file_get_contents(resource_path('views/components/layouts/app.blade.php'));

        foreach (['border-zinc-200', 'bg-white/85', 'backdrop-blur', 'dark:border-zinc-800', 'dark:bg-zinc-950/85'] as $token) {
            expect($header)->toContain($token)
                ->and($bar)->toContain($token);
        }
    });

    it('takes the header chrome off while the panel is open', function () {
        /*
         * Every class that dresses the bar as a header sabotages the `fixed`
         * panel inside it, and they fail differently:
         *
         *   backdrop-blur  a backdrop-filter is the CONTAINING BLOCK for fixed
         *                  descendants, like transform and filter. `inset-0`
         *                  resolved against the 33px bar, so full-screen search
         *                  opened as a 390x32 strip with Home live underneath.
         *   z-30           a stacking context caps the panel's z-50 at 30,
         *                  under the tab bar at z-40.
         *   sticky         opens a stacking context at `z-index: auto` too,
         *                  which `relative` does not — so dropping to z-auto
         *                  fixed nothing on its own.
         *
         * All three have to come off together; any one of them left on is a
         * different broken panel.
         */
        expect(Livewire::test('search-panel')->html())
            ->toContain(":class=\"{ 'sticky z-30 backdrop-blur': ! open }\"");
    });

    it('lights the League tab on a coach page', function () {
        $coach = Coach::create(['id' => 9, 'display_name' => 'Area Tester', 'last_name' => 'Tester']);

        $this->get(route('coach', $coach))
            ->assertOk()
            ->assertSee('aria-current="page"', escape: false);
    });
});
