<?php

use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\AthleteSeasonStat;
use App\Models\FeedRun;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Services\Stats\AggregateAthleteStats;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * cfb:aggregate is the longest-running database work in the app, so its write
 * path has to survive a connection dropped mid-run. Laravel reconnects and
 * replays a failed statement on its own — but ONLY while no transaction is
 * open (Connection::handleQueryException rethrows at transactions >= 1), so
 * the guarantee this file pins is structural: the chunk write is a single
 * idempotent statement with no transaction around it, and a failure that
 * survives the retry dies loudly in the ledger instead of truncating totals
 * silently.
 */

beforeEach(function () {
    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);

    $this->week = Week::create([
        'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
    ]);

    Team::factory()->create(['id' => 2633, 'slug' => 'tennessee', 'display_name' => 'Tennessee Volunteers']);
    Team::factory()->create(['id' => 2199, 'slug' => 'e-michigan', 'display_name' => 'Eastern Michigan Eagles']);

    $this->game = Game::factory()->finished()->create([
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 2633, 'away_team_id' => 2199,
    ]);

    Athlete::create(['id' => 910, 'display_name' => 'Chunk Passer']);
    Athlete::create(['id' => 911, 'display_name' => 'Chunk Rusher']);

    AthleteGameStat::create([
        'athlete_id' => 910, 'game_id' => $this->game->id, 'team_id' => 2633,
        'category' => 'passing',
        'stats' => ['completions/passingAttempts' => '20/30', 'passingYards' => '300', 'passingTouchdowns' => '3'],
    ]);
    AthleteGameStat::create([
        'athlete_id' => 911, 'game_id' => $this->game->id, 'team_id' => 2199,
        'category' => 'rushing',
        'stats' => ['rushingAttempts' => '18', 'rushingYards' => '92'],
    ]);
});

it('opens no transaction around the chunk write, so the lost-connection retry stays armed', function () {
    /*
     * The retry lives in Connection::run and is disabled the moment any
     * transaction is open — including the savepoint a DB::transaction inside
     * store() would take under this suite's own wrapping transaction. Zero
     * TransactionBeginning events during a pass is therefore the exact
     * precondition the reconnect-and-retry needs in production.
     */
    $began = 0;
    Event::listen(TransactionBeginning::class, function () use (&$began): void {
        $began++;
    });

    $written = app(AggregateAthleteStats::class)->handle(2025, Season::REGULAR);

    expect($written)->toBe(2)
        ->and($began)->toBe(0);

    // The same pin along the production call path: a transaction wrapped
    // around the command's own loop would disarm the retry just as surely,
    // while a service-only assertion stayed green.
    $this->artisan('cfb:aggregate', ['--year' => '2025', '--type' => (string) Season::REGULAR])
        ->assertSuccessful();

    expect($began)->toBe(0);
});

it('writes the same rows when a pass is replayed, which is what makes the retry safe to run', function () {
    // The reconnect replays the whole failed statement, so the write has to
    // converge on athlete_season_stats_unique rather than duplicate or drift.
    $aggregate = app(AggregateAthleteStats::class);

    $first = $aggregate->handle(2025, Season::REGULAR);
    $before = AthleteSeasonStat::orderBy('id')
        ->get(['id', 'athlete_id', 'category', 'team_id', 'stats'])
        ->map(fn (AthleteSeasonStat $row): array => $row->toArray())
        ->all();

    $second = $aggregate->handle(2025, Season::REGULAR);
    $after = AthleteSeasonStat::orderBy('id')
        ->get(['id', 'athlete_id', 'category', 'team_id', 'stats'])
        ->map(fn (AthleteSeasonStat $row): array => $row->toArray())
        ->all();

    // Same count returned, same row ids (updated in place, not re-inserted),
    // same totals.
    expect($second)->toBe($first)
        ->and($after)->toEqual($before)
        ->and(AthleteSeasonStat::count())->toBe(2);
});

it('surfaces a write that cannot recover and leaves the ledger saying the run died', function () {
    /*
     * A connection that stays down must not be swallowed: partial totals
     * presented as complete ones is the failure mode the coverage checks
     * exist to prevent. The poison throws from beforeExecuting, which sits
     * OUTSIDE Connection::run's retry, exactly like a reconnect that fails.
     */
    $armed = true;
    DB::connection()->beforeExecuting(function (string $query) use (&$armed): void {
        if ($armed && str_contains($query, 'athlete_season_stats')) {
            throw new QueryException(
                'mysql', $query, [],
                new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'),
            );
        }
    });

    expect(fn () => $this->artisan('cfb:aggregate', ['--year' => '2025', '--type' => (string) Season::REGULAR])->run())
        ->toThrow(QueryException::class);

    // The assertions below read the poisoned table themselves.
    $armed = false;

    $run = FeedRun::where('command', 'aggregate')->latest('id')->first();

    expect($run->status)->toBe(FeedRun::FAILED)
        ->and($run->error)->toContain('server has gone away')
        ->and(AthleteSeasonStat::count())->toBe(0);
});
