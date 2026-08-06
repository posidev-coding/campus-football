<?php

use App\Jobs\FetchGameSummary;
use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\Game;
use App\Models\GameScoringPlay;
use App\Models\GameSummary;
use App\Models\GameTeamStat;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\Week;
use App\Services\Espn\Sync\SyncGameSummary;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
    $this->week = Week::create([
        'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
    ]);

    $this->georgia = Team::factory()->create(['id' => 61, 'display_name' => 'Georgia Bulldogs', 'abbreviation' => 'UGA']);
    $this->alabama = Team::factory()->create(['id' => 333, 'display_name' => 'Alabama Crimson Tide', 'abbreviation' => 'ALA']);

    // Without these the FBS scope resolves to an empty team list and correctly
    // filters every game out — membership is season-scoped, so it has to exist.
    foreach ([61, 333] as $teamId) {
        TeamSeason::create([
            'team_id' => $teamId,
            'season_year' => 2025,
            'classification' => 'FBS',
        ]);
    }

    $this->game = Game::factory()->finished(31, 17)->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]);

    GameSummary::create([
        'game_id' => $this->game->id,
        'is_final' => true,
        'synced_at' => now(),
        'attendance' => 92746,
    ]);
});

it('renders a completed game for guests', function () {
    $this->get(route('game', $this->game))->assertOk();
});

it('costs no ESPN request for a final game', function () {
    // A final game's summary can never change, so it is fetched once ever and
    // every later visit is a pure database read. This is what makes an archive
    // of 5,000 game pages free to browse — nothing fetched, nothing queued.
    Http::fake();
    Queue::fake();

    Livewire::test('game', ['game' => $this->game])->assertOk();

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('renders the team box score in ESPN order, not MySQL JSON order', function () {
    // MySQL does not preserve JSON object key order, so ordering lives in a
    // JSON *array* alongside the map. Storing them scrambled here proves the
    // display order comes from display_stats and not from the map.
    GameTeamStat::create([
        'game_id' => $this->game->id,
        'team_id' => 61,
        'stats' => ['totalYards' => '461', 'firstDowns' => '21'],
        'display_stats' => [
            ['name' => 'firstDowns', 'label' => '1st Downs'],
            ['name' => 'totalYards', 'label' => 'Total Yards'],
        ],
    ]);

    $html = Livewire::test('game', ['game' => $this->game])->set('tab', 'box')->html();

    expect(strpos($html, '1st Downs'))->toBeLessThan(strpos($html, 'Total Yards'));
});

it('renders player lines keyed by name rather than array position', function () {
    $athlete = Athlete::create(['id' => 4690158, 'display_name' => 'Noah Kim', 'slug' => 'noah-kim']);

    AthleteGameStat::create([
        'athlete_id' => $athlete->id,
        'game_id' => $this->game->id,
        'team_id' => 61,
        'category' => 'passing',
        // Deliberately out of display order — v3 indexed stats[0]/stats[1] and
        // broke whenever ESPN reordered.
        'stats' => ['passingYards' => '330', 'completions/passingAttempts' => '25/42'],
        'display_stats' => [
            ['name' => 'completions/passingAttempts', 'label' => 'C/ATT'],
            ['name' => 'passingYards', 'label' => 'YDS'],
        ],
    ]);

    Livewire::test('game', ['game' => $this->game])
        ->set('tab', 'box')
        ->assertSee('Noah Kim')
        ->assertSee('25/42')
        ->assertSee('330');
});

it('orders scoring plays by sequence, not by clock', function () {
    // A football clock counts DOWN, so ascending clock within a quarter
    // reverses it. These two are stored with the later play having the LARGER
    // clock value to make that failure mode visible.
    GameScoringPlay::create([
        'game_id' => $this->game->id, 'team_id' => 61, 'sequence' => 1,
        'period' => 1, 'clock' => '2:11', 'abbreviation' => 'TD',
        'text' => 'First score of the game', 'home_score' => 7, 'away_score' => 0,
    ]);

    GameScoringPlay::create([
        'game_id' => $this->game->id, 'team_id' => 333, 'sequence' => 2,
        'period' => 2, 'clock' => '14:55', 'abbreviation' => 'TD',
        'text' => 'Second score of the game', 'home_score' => 7, 'away_score' => 7,
    ]);

    $html = Livewire::test('game', ['game' => $this->game])->set('tab', 'scoring')->html();

    expect(strpos($html, 'First score'))->toBeLessThan(strpos($html, 'Second score'));
});

it('shows attendance from the summary', function () {
    Livewire::test('game', ['game' => $this->game])->assertSee('92,746');
});

it('tells the user an upcoming game has no box score yet', function () {
    $upcoming = Game::factory()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
        'completed' => false, 'status' => 'pre',
    ]);

    Livewire::test('game', ['game' => $upcoming])
        ->assertOk()
        ->assertSee('Not played yet');
});

it('never fetches ESPN synchronously, even for a live game', function () {
    /*
     * The page used to fetch the 544 KB summary INLINE in the Livewire
     * request — the slow path on the one screen people refresh most. It
     * queues a refresh now (the athlete game-log pattern) and renders
     * whatever is stored.
     */
    Http::fake();
    Queue::fake();

    $live = Game::factory()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
        'completed' => false, 'status' => 'in',
        // Pinned: an unpinned kickoff drifts into other tests' date-window
        // queries and shifts the faker sequence beneath them.
        'kickoff_at' => '2025-09-27 19:30:00',
    ]);

    Livewire::test('game', ['game' => $live])->assertOk();

    Http::assertNothingSent();
    // On the live queue, so it is picked up in seconds even while a backfill
    // batch drains.
    Queue::assertPushedOn('live', FetchGameSummary::class);
    Queue::assertPushed(FetchGameSummary::class, fn (FetchGameSummary $job) => $job->force === false);
});

it('queues one refresh for a live game, not one per viewer', function () {
    /*
     * The invariant that keeps this screen cheap: the job is unique on the
     * game, so a second viewer mounting while the first's job is still
     * queued adds NOTHING. (Queue::fake honors ShouldBeUnique locks.)
     */
    Queue::fake();

    $live = Game::factory()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
        'completed' => false, 'status' => 'in',
        // Pinned: an unpinned kickoff drifts into other tests' date-window
        // queries and shifts the faker sequence beneath them.
        'kickoff_at' => '2025-09-27 19:30:00',
    ]);

    foreach (range(1, 3) as $viewer) {
        Livewire::test('game', ['game' => $live->fresh()])->assertOk();
    }

    Queue::assertPushed(FetchGameSummary::class, 1);
});

it('queues nothing for a fresh live summary', function () {
    // The staleness window is the per-game throttle now: a summary synced
    // seconds ago means the next viewer dispatches nothing at all.
    Queue::fake();

    $live = Game::factory()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
        'completed' => false, 'status' => 'in',
        // Pinned: an unpinned kickoff drifts into other tests' date-window
        // queries and shifts the faker sequence beneath them.
        'kickoff_at' => '2025-09-27 19:30:00',
    ]);

    GameSummary::create([
        'game_id' => $live->id,
        'is_final' => false,
        'synced_at' => now(),
    ]);

    Livewire::test('game', ['game' => $live])->assertOk();

    Queue::assertNothingPushed();
});

it('queues nothing pregame', function () {
    // The summary payload has no box score before kickoff; the old inline
    // refresh burned one 544 KB request a minute on every upcoming game
    // somebody left open.
    Queue::fake();

    $upcoming = Game::factory()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
        'completed' => false, 'status' => 'pre',
    ]);

    Livewire::test('game', ['game' => $upcoming])->assertOk();

    Queue::assertNothingPushed();
});

it('queues a refresh for a completed game whose final fetch was swallowed', function () {
    // A completed game wearing a mid-game summary means the just-final fetch
    // died (crashed worker, cancelled batch). Waiting for staleness would be
    // fine; waiting forever would not — isStale(Game) treats it as due.
    Queue::fake();

    GameSummary::where('game_id', $this->game->id)->update(['is_final' => false, 'synced_at' => now()]);

    Livewire::test('game', ['game' => $this->game->fresh()])->assertOk();

    Queue::assertPushedOn('live', FetchGameSummary::class);
});

it('links a game card to its game page', function () {
    Livewire::test('scoreboard')
        ->set('scope', 'fbs')
        ->set('week', $this->week->id)
        ->assertSee(route('game', $this->game), escape: false);
});

it('survives a negative running score from ESPN', function () {
    /*
     * Verified live: game 401767129 carries a scoring play with
     * `homeScore: -14`. A running score cannot be negative, the column is
     * unsigned, and writing it raw threw — which aborted a 954-game backfill at
     * game 260 over one corrupt row.
     *
     * Null rather than clamped to zero: we do not know what the score was, and
     * inventing 0 renders a confidently wrong scoreline.
     */
    Http::fake(['*' => Http::response([
        'boxscore' => ['teams' => [], 'players' => []],
        'scoringPlays' => [[
            'text' => 'Ernest Campbell 22 Yd pass from Cardell Williams',
            'homeScore' => -14,
            'awayScore' => 0,
            'period' => ['number' => 1],
            'clock' => ['displayValue' => '0:05'],
            'type' => ['text' => 'Passing Touchdown'],
            'scoringType' => ['abbreviation' => 'TD'],
            'team' => ['id' => 61],
        ]],
    ])]);

    $sync = app(SyncGameSummary::class);

    expect(fn () => $sync->handle($this->game))->not->toThrow(Throwable::class);

    $play = GameScoringPlay::where('game_id', $this->game->id)->first();

    expect($play)->not->toBeNull()
        ->and($play->home_score)->toBeNull()
        ->and($play->away_score)->toBe(0)
        // The play itself is still worth having.
        ->and($play->text)->toContain('Ernest Campbell');
});
