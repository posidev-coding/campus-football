<?php

use App\Models\Article;
use App\Models\Athlete;
use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Models\Game;
use App\Models\NationalLeader;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\TeamSeasonStat;
use App\Models\Week;
use App\Support\Scope;
use App\Support\Stats\LeaderQuery;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->season = Season::factory()->create([
        'year' => 2025, 'type' => Season::REGULAR,
        'start_date' => '2025-08-23', 'end_date' => '2025-12-13',
    ]);

    $this->postseason = Season::factory()->create([
        'year' => 2025, 'type' => Season::POSTSEASON,
        'start_date' => '2025-12-13', 'end_date' => '2026-01-21',
    ]);

    $this->sec = Conference::factory()->create([
        'id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC', 'abbreviation' => 'sec',
    ]);

    ConferenceSeason::create([
        'conference_id' => 8, 'season_year' => 2025, 'classification' => 'FBS',
    ]);

    $this->georgia = Team::factory()->create(['id' => 61, 'slug' => 'georgia-bulldogs', 'display_name' => 'Georgia Bulldogs']);
    $this->fcsTeam = Team::factory()->create(['id' => 2000, 'slug' => 'small-college', 'display_name' => 'Small College']);

    TeamSeason::create(['team_id' => 61, 'season_year' => 2025, 'conference_id' => 8, 'classification' => 'FBS']);
    TeamSeason::create(['team_id' => 2000, 'season_year' => 2025, 'classification' => 'FCS']);
});

describe('teams index', function () {
    it('renders for guests', function () {
        $this->get(route('teams'))->assertOk();
    });

    it('groups teams by their conference in that season', function () {
        Livewire::test('teams')
            ->set('year', 2025)
            ->assertSee('Georgia Bulldogs')
            ->assertDontSee('Small College');
    });

    it('filters by name', function () {
        Team::factory()->create(['display_name' => 'Auburn Tigers', 'id' => 2]);
        TeamSeason::create(['team_id' => 2, 'season_year' => 2025, 'conference_id' => 8, 'classification' => 'FBS']);

        Livewire::test('teams')
            ->set('year', 2025)
            ->set('q', 'Georgia')
            ->assertSee('Georgia Bulldogs')
            ->assertDontSee('Auburn Tigers');
    });
});

describe('national leaders', function () {
    beforeEach(function () {
        $this->passer = Athlete::create(['id' => 5219834, 'slug' => 'top-passer', 'display_name' => 'Top Passer']);
        $this->fcsPasser = Athlete::create(['id' => 999, 'slug' => 'fcs-passer', 'display_name' => 'FCS Passer']);

        NationalLeader::create([
            'season_year' => 2025, 'season_type' => Season::REGULAR, 'category' => 'passingYards',
            'athlete_id' => $this->passer->id, 'team_id' => 61, 'rank' => 1,
            'value' => 4129, 'display_value' => '4129',
        ]);

        NationalLeader::create([
            'season_year' => 2025, 'season_type' => Season::REGULAR, 'category' => 'passingYards',
            'athlete_id' => $this->fcsPasser->id, 'team_id' => 2000, 'rank' => 2,
            'value' => 3900, 'display_value' => '3900',
        ]);
    });

    it('renders for guests', function () {
        $this->get(route('leaders'))->assertOk();
    });

    it('keeps the national feed as a cross-check, not as the screen', function () {
        /*
         * `national_leaders` is still synced, but the leaders SCREEN no longer
         * reads it — that feed spans every division and only ~half its top 100
         * is FBS, so a scoped view read from it collapsed. It survives as a
         * cross-check against our derived totals, the same dual-source
         * discipline the standings reconciler uses.
         *
         * Scoping is exercised properly in DerivedLeadersTest.
         */
        expect(NationalLeader::where('season_year', 2025)->count())->toBeGreaterThan(0);

        $fbs = Scope::teamIds('fbs', 2025);

        expect(NationalLeader::where('season_year', 2025)->whereIn('team_id', $fbs)->count())
            ->toBeLessThan(NationalLeader::where('season_year', 2025)->count());
    });

    it('renders without erroring when a season has no derived stats', function () {
        // 2026 is scheduled but unplayed, so there are no box scores to derive
        // from. An empty state, not a crash.
        Livewire::test('leaders')->set('year', 2026)->assertOk()->assertSee('No statistics');
    });
});

describe('national team stats', function () {
    it('renders for guests', function () {
        $this->get(route('stats'))->assertOk();
    });

    it('ranks teams within the scope, not by ESPN national rank', function () {
        /*
         * ESPN's national rank is carried for context but is the wrong number
         * to ORDER by once a conference is selected — the SEC's best offence
         * should be row 1, not row 7.
         */
        $rival = Team::factory()->create(['id' => 333, 'slug' => 'alabama', 'display_name' => 'Alabama Crimson Tide']);
        TeamSeason::create(['team_id' => 333, 'season_year' => 2025, 'conference_id' => 8, 'classification' => 'FBS']);

        TeamSeasonStat::create([
            'team_id' => 61, 'season_year' => 2025, 'season_type' => Season::REGULAR, 'category' => 'scoring',
            'stats' => ['totalPoints' => ['display' => '415', 'value' => 415, 'rank' => 21, 'label' => 'Total Points']],
        ]);

        TeamSeasonStat::create([
            'team_id' => 333, 'season_year' => 2025, 'season_type' => Season::REGULAR, 'category' => 'scoring',
            'stats' => ['totalPoints' => ['display' => '520', 'value' => 520, 'rank' => 3, 'label' => 'Total Points']],
        ]);

        $board = ['group' => 'Scoring', 'category' => 'scoring', 'stat' => 'totalPoints', 'label' => 'Points'];
        $rows = LeaderQuery::teams($board, 2025, 'fbs');

        // Alabama scored more, so it is row 1 whatever its national rank says.
        expect($rows[0]['team_id'])->toBe(333)
            ->and($rows[0]['rank'])->toBe(1)
            ->and($rows[0]['national'])->toBe(3)
            ->and($rows[1]['rank'])->toBe(2);
    });

    it('tolerates the pre-rank flat stat shape', function () {
        // A page rendered midway through a re-sync must degrade to showing the
        // value without a rank rather than erroring on a string offset.
        TeamSeasonStat::create([
            'team_id' => 61, 'season_year' => 2025, 'season_type' => Season::REGULAR, 'category' => 'passing',
            'stats' => ['passingYards' => '3200'],
        ]);

        expect(TeamSeasonStat::where('team_id', 61)->first()->stat('passingYards'))
            ->toMatchArray(['display' => '3200', 'rank' => null]);
    });
});

describe('conference page', function () {
    it('renders for guests', function () {
        // With an attached article: the article card renders team chips, and
        // lazy loading is disabled app-wide, so a missing eager load is a hard
        // 500 rather than a silent N+1. A fixture with no articles never
        // exercises that path.
        $article = Article::create(['espn_id' => 77, 'headline' => 'SEC news', 'published_at' => now()]);
        $article->teams()->attach(61);

        $this->get(route('conference', $this->sec))->assertOk()->assertSee('SEC news');
    });

    it('shows the conference standings for the season', function () {
        Standing::create([
            'season_year' => 2025, 'conference_id' => 8, 'team_id' => 61, 'source' => 'espn',
            'overall_wins' => 12, 'overall_losses' => 1, 'conf_wins' => 7, 'conf_losses' => 1,
            'streak' => 'W9',
        ]);

        Livewire::test('conference', ['conference' => $this->sec])
            ->set('year', 2025)
            ->assertSee('Georgia Bulldogs')
            ->assertSee('7-1')
            ->assertSee('W9');
    });

    it('is what a conference link points at', function () {
        // Every conference link used to deep-link to a filtered standings page,
        // which answers one question and drops the reader elsewhere.
        Livewire::test('teams')
            ->set('year', 2025)
            ->assertSee(route('conference', ['conference' => 8, 'year' => 2025]), escape: false);
    });
});

describe('news', function () {
    it('renders for guests', function () {
        $this->get(route('news'))->assertOk();
    });

    it('shows articles newest first', function () {
        Article::create(['espn_id' => 1, 'headline' => 'Older story', 'published_at' => now()->subDay()]);
        Article::create(['espn_id' => 2, 'headline' => 'Newer story', 'published_at' => now()]);

        $html = Livewire::test('news')->html();

        expect(strpos($html, 'Newer story'))->toBeLessThan(strpos($html, 'Older story'));
    });
});

describe('bowls', function () {
    it('renders for guests', function () {
        $this->get(route('bowls'))->assertOk();
    });

    it('reads the postseason season type, not a week number', function () {
        $week = Week::create([
            'season_id' => $this->postseason->id, 'number' => 1, 'name' => 'Bowls',
            'start_date' => '2025-12-13', 'end_date' => '2026-01-21',
        ]);

        Game::factory()->finished()->create([
            'season_id' => $this->postseason->id, 'week_id' => $week->id,
            'home_team_id' => 61, 'away_team_id' => 2000,
            'name' => 'Sugar Bowl',
        ]);

        Livewire::test('bowls')->set('year', 2025)->assertSee('Georgia Bulldogs');
    });
});

describe('global search', function () {
    it('finds teams, players and conferences', function () {
        Athlete::create(['id' => 1, 'slug' => 'georgia-player', 'display_name' => 'George Player']);

        Livewire::test('search')
            ->set('q', 'Georg')
            ->assertSee('Georgia Bulldogs')
            ->assertSee('George Player');
    });

    it('ignores a query too short to be useful', function () {
        Livewire::test('search')
            ->set('q', 'G')
            ->assertSee('Type at least two characters');
    });

    it('does not call ESPN', function () {
        // Search is the fastest interaction in the app; putting an external
        // dependency behind it would be the wrong trade.
        Http::fake();

        Livewire::test('search')->set('q', 'Georgia')->assertOk();

        Http::assertNothingSent();
    });
});
