<?php

use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\AthleteSeasonStat;
use App\Models\Conference;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\TeamSeasonStat;
use App\Models\Week;
use App\Services\Stats\AggregateAthleteStats;
use App\Support\Scope;
use App\Support\Stats\LeaderQuery;
use App\Support\Stats\StatCatalog;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->regular = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
    $this->post = Season::factory()->create(['year' => 2025, 'type' => Season::POSTSEASON]);

    $this->week = Week::create([
        'season_id' => $this->regular->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
    ]);
    $this->bowlWeek = Week::create([
        'season_id' => $this->post->id, 'number' => 1, 'name' => 'Bowls',
        'start_date' => '2025-12-13', 'end_date' => '2026-01-21',
    ]);

    Conference::factory()->create(['id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC']);
    Conference::factory()->create(['id' => 15, 'name' => 'Mid-American Conference', 'short_name' => 'MAC']);

    $this->sec = Team::factory()->create(['id' => 61, 'slug' => 'georgia', 'display_name' => 'Georgia Bulldogs']);
    $this->mac = Team::factory()->create(['id' => 2199, 'slug' => 'e-michigan', 'display_name' => 'Eastern Michigan Eagles']);

    TeamSeason::create(['team_id' => 61, 'season_year' => 2025, 'conference_id' => 8, 'classification' => 'FBS']);
    TeamSeason::create(['team_id' => 2199, 'season_year' => 2025, 'conference_id' => 15, 'classification' => 'FBS']);

    $this->game = Game::factory()->finished()->create([
        'season_id' => $this->regular->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 2199,
    ]);
    $this->bowl = Game::factory()->finished()->create([
        'season_id' => $this->post->id, 'week_id' => $this->bowlWeek->id,
        'home_team_id' => 61, 'away_team_id' => 2199,
    ]);
});

function passingLine(int $athleteId, int $teamId, int $gameId, string $compAtt, int $yards, int $tds = 0, int $long = 0): void
{
    AthleteGameStat::create([
        'athlete_id' => $athleteId,
        'game_id' => $gameId,
        'team_id' => $teamId,
        'category' => 'passing',
        'stats' => [
            'completions/passingAttempts' => $compAtt,
            'passingYards' => (string) $yards,
            'passingTouchdowns' => (string) $tds,
            'yardsPerPassAttempt' => '99.9',   // a per-game rate; must be recomputed
            'adjQBR' => '88.8',                 // proprietary; must be dropped
            'longRushing' => (string) $long,    // a max stat, not a sum
        ],
    ]);
}

describe('aggregation', function () {
    it('sums counting stats across games', function () {
        $a = Athlete::create(['id' => 900, 'display_name' => 'Test Passer']);

        passingLine(900, 61, $this->game->id, '20/30', 300, 3);

        app(AggregateAthleteStats::class)->handle(2025, Season::REGULAR);

        $row = AthleteSeasonStat::where('athlete_id', 900)->where('season_type', Season::REGULAR)->first();

        // Cast: MySQL hands whole numbers back from JSON as int, so a strict
        // float comparison fails on a value that is numerically correct.
        expect((float) $row->stats['passingYards'])->toBe(300.0)
            ->and((float) $row->stats['passingTouchdowns'])->toBe(3.0)
            // "20/30" split into components so both can be summed.
            ->and((float) $row->stats['completions'])->toBe(20.0)
            ->and((float) $row->stats['passingAttempts'])->toBe(30.0);
    });

    it('recomputes rate stats instead of averaging per-game rates', function () {
        /*
         * Averaging averages weights a 1-attempt game the same as a 40-attempt
         * one. Each game here claims 99.9 yards per attempt; the true season
         * figure is 400/50 = 8.0.
         */
        Athlete::create(['id' => 901, 'display_name' => 'Rate Passer']);

        passingLine(901, 61, $this->game->id, '10/20', 100);
        passingLine(901, 61, $this->bowl->id, '20/30', 300);

        app(AggregateAthleteStats::class)->handle(2025, AggregateAthleteStats::FULL_SEASON);

        $row = AthleteSeasonStat::where('athlete_id', 901)
            ->where('season_type', AggregateAthleteStats::FULL_SEASON)->first();

        expect((float) $row->stats['yardsPerPassAttempt'])->toBe(8.0)
            ->and((float) $row->stats['yardsPerPassAttempt'])->not->toBe(99.9);
    });

    it('takes the max for longest-play stats rather than summing them', function () {
        // A season's longest run is the longest single run, not the total of
        // every game's longest.
        Athlete::create(['id' => 902, 'display_name' => 'Long Runner']);

        passingLine(902, 61, $this->game->id, '1/1', 10, 0, long: 44);
        passingLine(902, 61, $this->bowl->id, '1/1', 10, 0, long: 61);

        app(AggregateAthleteStats::class)->handle(2025, AggregateAthleteStats::FULL_SEASON);

        expect(AthleteSeasonStat::where('athlete_id', 902)
            ->where('season_type', AggregateAthleteStats::FULL_SEASON)
            ->first()->stats['longRushing'])->toEqual(61);
    });

    it('drops stats it cannot honestly derive', function () {
        // adjQBR is a proprietary model, not an arithmetic combination of the
        // columns beside it. Approximating it would be inventing a number.
        Athlete::create(['id' => 903, 'display_name' => 'QBR Passer']);

        passingLine(903, 61, $this->game->id, '10/20', 200);

        app(AggregateAthleteStats::class)->handle(2025, Season::REGULAR);

        expect(AthleteSeasonStat::where('athlete_id', 903)->first()->stats)
            ->not->toHaveKey('adjQBR');
    });

    it('folds every season type into a full-season row', function () {
        // ESPN's headline leaders are cumulative — it reports 4,379 for a
        // passer whose regular season was 4,129.
        Athlete::create(['id' => 904, 'display_name' => 'Bowl Passer']);

        passingLine(904, 61, $this->game->id, '10/20', 4129);
        passingLine(904, 61, $this->bowl->id, '10/20', 250);

        $aggregate = app(AggregateAthleteStats::class);
        $aggregate->handle(2025, Season::REGULAR);
        $aggregate->handle(2025, AggregateAthleteStats::FULL_SEASON);

        $regular = AthleteSeasonStat::where('athlete_id', 904)->where('season_type', Season::REGULAR)->first();
        $full = AthleteSeasonStat::where('athlete_id', 904)->where('season_type', AggregateAthleteStats::FULL_SEASON)->first();

        expect((float) $regular->stats['passingYards'])->toBe(4129.0)
            ->and((float) $full->stats['passingYards'])->toBe(4379.0);
    });

    it('costs no ESPN request', function () {
        // It is arithmetic over data we already hold, not a feed.
        Http::fake();

        Athlete::create(['id' => 905, 'display_name' => 'Free Passer']);
        passingLine(905, 61, $this->game->id, '10/20', 200);

        app(AggregateAthleteStats::class)->handle(2025, Season::REGULAR);

        Http::assertNothingSent();
    });
});

describe('scoped ranking', function () {
    beforeEach(function () {
        Athlete::create(['id' => 910, 'slug' => 'sec-qb', 'display_name' => 'SEC Passer']);
        Athlete::create(['id' => 911, 'slug' => 'mac-qb', 'display_name' => 'MAC Passer']);

        passingLine(910, 61, $this->game->id, '30/40', 4000, 40);
        passingLine(911, 2199, $this->game->id, '20/30', 2800, 20);

        app(AggregateAthleteStats::class)->handle(2025, AggregateAthleteStats::FULL_SEASON);
    });

    it('numbers a conference leaderboard from 1', function () {
        /*
         * The whole reason for deriving. ESPN's national feed spans every
         * division and only ~half its top 100 is FBS, so a conference view
         * showed whichever few players cracked a national board — the MAC had
         * FOUR — with non-contiguous ranks.
         */
        $board = collect(StatCatalog::leaderboards()[StatCatalog::OFFENSE])->firstWhere('stat', 'passingYards');

        $rows = LeaderQuery::players($board, 2025, '15');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['rank'])->toBe(1)
            ->and($rows[0]['athlete_id'])->toBe(911);
    });

    it('excludes players outside the scope', function () {
        $board = collect(StatCatalog::leaderboards()[StatCatalog::OFFENSE])->firstWhere('stat', 'passingYards');

        expect(collect(LeaderQuery::players($board, 2025, '8'))->pluck('athlete_id'))
            ->toContain(910)
            ->not->toContain(911);
    });

    it('applies a minimum-attempts floor to rate leaderboards', function () {
        // Without a floor, a 1-for-1 passer at 20 yards per attempt tops the
        // board ahead of everyone who actually played.
        Athlete::create(['id' => 912, 'display_name' => 'One Throw']);
        passingLine(912, 61, $this->game->id, '1/1', 20);

        app(AggregateAthleteStats::class)->handle(2025, AggregateAthleteStats::FULL_SEASON);

        $board = collect(StatCatalog::leaderboards()[StatCatalog::OFFENSE])->firstWhere('stat', 'yardsPerPassAttempt');

        expect(collect(LeaderQuery::players($board, 2025, Scope::FBS))->pluck('athlete_id'))
            ->not->toContain(912);
    });
});

describe('screens', function () {
    it('renders leaders and team stats for guests', function () {
        $this->get(route('leaders'))->assertOk();
        $this->get(route('stats'))->assertOk();
    });

    it('offers no Top 25 scope on either', function () {
        /*
         * Top 25 filters TEAMS. On a scoreboard that means "the games that
         * matter"; on a leaderboard it silently means "the leading rusher among
         * 25 teams" and reads as if it were the national leader.
         */
        $options = collect(Scope::options(2025, includeFcs: false, top25: false))->pluck('value');

        expect($options)->not->toContain(Scope::TOP_25)
            ->and($options->first())->toBe(Scope::FBS);
    });

    it('rewrites a bookmarked Top 25 url rather than honouring it', function () {
        foreach (['leaders', 'stats'] as $component) {
            expect(Livewire::test($component)->set('scope', Scope::TOP_25)->get('scope'))
                ->not->toBe(Scope::TOP_25);
        }
    });

    it('groups player stats into offense, defense and special teams', function () {
        expect(array_keys(StatCatalog::leaderboards()))
            ->toBe([StatCatalog::OFFENSE, StatCatalog::DEFENSE, StatCatalog::SPECIAL]);

        // A group heading only renders when its boards have rows, so the side
        // needs real production behind it.
        Athlete::create(['id' => 920, 'slug' => 'lb', 'display_name' => 'Test Linebacker']);

        AthleteGameStat::create([
            'athlete_id' => 920, 'game_id' => $this->game->id, 'team_id' => 61,
            'category' => 'defensive',
            'stats' => ['totalTackles' => '12', 'soloTackles' => '7', 'sacks' => '2.0'],
        ]);

        app(AggregateAthleteStats::class)->handle(2025, AggregateAthleteStats::FULL_SEASON);

        Livewire::test('leaders')->set('year', 2025)->set('side', StatCatalog::DEFENSE)
            ->assertSee('Tackles')
            ->assertSee('Test Linebacker')
            ->assertDontSee('Receiving Yards');
    });

    it('groups team stats the same way', function () {
        TeamSeasonStat::create([
            'team_id' => 61, 'season_year' => 2025, 'season_type' => Season::REGULAR,
            'category' => 'defensiveInterceptions',
            'stats' => ['interceptions' => ['display' => '18', 'value' => 18, 'rank' => 2, 'label' => 'Interceptions']],
        ]);

        Livewire::test('stats')->set('year', 2025)->set('side', StatCatalog::DEFENSE)
            ->assertOk()
            ->assertSee('Takeaways');
    });

    it('keeps caught interceptions separate from thrown ones', function () {
        /*
         * `interceptions` exists in BOTH the passing category (thrown — a bad
         * outcome) and the interceptions category (caught — a good one). Same
         * key, opposite meaning. Keying a board on the stat name alone would
         * rank quarterbacks by how often they were picked off and call them
         * leaders.
         */
        $board = collect(StatCatalog::leaderboards()[StatCatalog::DEFENSE])
            ->firstWhere('label', 'Interceptions');

        expect($board['category'])->toBe('interceptions')
            ->and($board['category'])->not->toBe('passing');
    });
});
