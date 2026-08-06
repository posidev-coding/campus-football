<?php

use App\Jobs\FetchGameSummary;
use App\Jobs\Middleware\ThrottleEspn;
use App\Models\Game;
use App\Models\GameDrive;
use App\Models\GameSummary;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Services\Espn\Sync\SyncGameSummary;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
    $this->week = Week::create([
        'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
    ]);

    Team::factory()->create(['id' => 61, 'display_name' => 'Georgia Bulldogs']);
    Team::factory()->create(['id' => 333, 'display_name' => 'Alabama Crimson Tide']);

    $this->games = collect(range(1, 3))->map(fn () => Game::factory()->finished()->create([
        'season_id' => $this->season->id,
        'week_id' => $this->week->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
    ]));
});

it('queues one job per game rather than looping in-process', function () {
    /*
     * The sequential version died at PHP's 128 MB limit partway through a
     * 693-game run — memory grew about a megabyte a game and a single
     * long-lived process never gave it back. A job per game bounds memory to
     * one payload.
     */
    Bus::fake();

    $this->artisan('cfb:summaries --year=2025')->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 3
        && $batch->jobs->every(fn ($job) => $job instanceof FetchGameSummary));
});

it('drains on the backfill queue, forced past the staleness check', function () {
    /*
     * `backfill` so a thousand-game drain cannot starve the live queue's
     * seconds-level pickup on a game day; forced because --missing targets
     * games with no summary and --force re-fetches deliberately — the
     * staleness re-check must not apply to either.
     */
    Bus::fake();

    $this->artisan('cfb:summaries --year=2025')->assertSuccessful();

    Bus::assertBatched(fn ($batch) => ($batch->options['queue'] ?? null) === 'backfill'
        && $batch->jobs->every(fn (FetchGameSummary $job) => $job->force === true));
});

it('lets one bad game fail without cancelling the batch', function () {
    // ESPN game 401767129 carries a scoring play with a negative score. Before
    // allowFailures(), one such row ended a 954-game run at game 260.
    Bus::fake();

    $this->artisan('cfb:summaries --year=2025')->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->options['allowFailures'] ?? false);
});

it('skips games that already have a summary', function () {
    // A final game's summary can never change, so --missing makes every re-run
    // a resume rather than a restart.
    GameSummary::create([
        'game_id' => $this->games->first()->id,
        'is_final' => true,
        'synced_at' => now(),
    ]);

    Bus::fake();

    $this->artisan('cfb:summaries --year=2025')->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);
});

it('reports nothing to do rather than queueing an empty batch', function () {
    foreach ($this->games as $game) {
        GameSummary::create(['game_id' => $game->id, 'is_final' => true, 'synced_at' => now()]);
    }

    Bus::fake();

    $this->artisan('cfb:summaries --year=2025')
        ->expectsOutputToContain('Nothing to sync')
        ->assertSuccessful();

    Bus::assertNothingBatched();
});

it('deduplicates on the game, so a double dispatch is one fetch', function () {
    $job = new FetchGameSummary(401756846);

    expect($job->uniqueId())->toBe('401756846')
        ->and($job->uniqueId())->not->toBe((new FetchGameSummary(1))->uniqueId());
});

it('keeps the job timeout below the queue retry_after', function () {
    // v3 had this backwards and re-ran long jobs while the first copy was still
    // executing. Checked against every connection that defines one, because the
    // suite runs on `sync`, which does not.
    $timeout = (new FetchGameSummary(1))->timeout;
    $checked = 0;

    foreach (config('queue.connections') as $name => $connection) {
        if (! isset($connection['retry_after'])) {
            continue;
        }

        expect($timeout)->toBeLessThan($connection['retry_after'], "Under [{$name}] retry_after.");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
});

it('actually stores a summary when the job runs', function () {
    Http::fake(['*' => Http::response([
        'boxscore' => [
            'teams' => [[
                'team' => ['id' => 61],
                'statistics' => [['name' => 'totalYards', 'displayValue' => '461', 'label' => 'Total Yards']],
            ]],
            'players' => [],
        ],
        'scoringPlays' => [],
        'header' => ['competitions' => [['status' => ['type' => ['completed' => true]]]]],
        'gameInfo' => ['attendance' => 92746],
    ])]);

    $game = $this->games->first();

    (new FetchGameSummary($game->id))->handle(app(SyncGameSummary::class));

    expect(GameSummary::whereKey($game->id)->exists())->toBeTrue()
        ->and(GameSummary::find($game->id)->attendance)->toBe(92746)
        ->and($game->teamStats()->count())->toBe(1);
});

describe('drives live on their own table', function () {
    /*
     * Measured before the split: game_summaries was 1,764 MB from 4,844 rows
     * — 86% of the entire database — because `drives` averages 306 KB a row.
     * The game page loads its summary with a plain first(), a SELECT *, so
     * every view of every game read all of it to render a box score that
     * never touches it.
     */
    beforeEach(function () {
        Http::fake(['*' => Http::response([
            'boxscore' => ['teams' => [], 'players' => []],
            'drives' => ['previous' => [['id' => '1', 'description' => '9 plays, 75 yards']]],
            'header' => ['competitions' => [['status' => ['type' => ['completed' => true]]]]],
            'gameInfo' => ['attendance' => 92746],
        ])]);
    });

    it('writes drives to game_drives, not game_summaries', function () {
        $game = $this->games->first();

        (new FetchGameSummary($game->id, force: true))->handle(app(SyncGameSummary::class));

        expect(GameDrive::whereKey($game->id)->exists())->toBeTrue()
            ->and(GameDrive::find($game->id)->drives)->toHaveCount(1)
            // The summary row keeps only what a game page renders.
            ->and(Schema::hasColumn('game_summaries', 'drives'))->toBeFalse()
            ->and(GameSummary::find($game->id)->attendance)->toBe(92746);
    });

    it('keeps the summary row small enough to read on every view', function () {
        // The whole point of the split: loading a summary must not drag the
        // drive chart with it. A relation exists for the screen that will
        // render drives; nothing eager-loads it.
        $game = $this->games->first();

        (new FetchGameSummary($game->id, force: true))->handle(app(SyncGameSummary::class));

        $summary = GameSummary::find($game->id);

        expect($summary->relationLoaded('drives'))->toBeFalse()
            ->and($summary->getAttributes())->not->toHaveKey('drives')
            // ...and it is still reachable when something asks for it.
            ->and($summary->drives()->first()->drives)->toHaveCount(1);
    });

    it('replaces drives rather than duplicating them on re-sync', function () {
        $game = $this->games->first();
        $sync = app(SyncGameSummary::class);

        (new FetchGameSummary($game->id, force: true))->handle($sync);
        (new FetchGameSummary($game->id, force: true))->handle($sync);

        expect(GameDrive::where('game_id', $game->id)->count())->toBe(1);
    });
});

it('does nothing for a game that no longer exists', function () {
    Http::fake();

    (new FetchGameSummary(999999999))->handle(app(SyncGameSummary::class));

    Http::assertNothingSent();
});

it('still supports an in-process run for one game', function () {
    // --now keeps the old path for debugging and single games, where spinning
    // up a worker is more trouble than the work.
    Http::fake(['*' => Http::response(['boxscore' => ['teams' => [], 'players' => []]])]);
    Queue::fake();

    $this->artisan('cfb:summaries --now --game='.$this->games->first()->id)
        ->assertSuccessful();

    Queue::assertNothingPushed();
    expect(GameSummary::whereKey($this->games->first()->id)->exists())->toBeTrue();
});

it('releases the worker instead of sleeping when the allowance is spent', function () {
    /*
     * The prerequisite for any fan-out. EspnClient's own throttle BLOCKS —
     * `while (tooManyAttempts) usleep(250ms)` — which is right for a
     * synchronous caller with nowhere to defer to and actively harmful on a
     * queue: Laravel's RateLimiter is a FIXED WINDOW, so once the minute is
     * spent every worker spins for up to 60s, jobs hit their 60s timeout
     * mid-wait, and throughput goes DOWN as workers are added.
     */
    $limit = (int) config('espn.http.rate_limit');

    RateLimiter::clear('espn-api');

    for ($i = 0; $i < $limit; $i++) {
        RateLimiter::hit('espn-api', 60);
    }

    $job = Mockery::mock(FetchGameSummary::class)->makePartial();
    $job->shouldReceive('release')->once()->with(Mockery::type('int'));

    $reached = false;

    (new ThrottleEspn)->handle($job, function () use (&$reached) {
        $reached = true;
    });

    expect($reached)->toBeFalse();

    RateLimiter::clear('espn-api');
});

it('passes the job straight through when there is allowance left', function () {
    RateLimiter::clear('espn-api');

    $job = Mockery::mock(FetchGameSummary::class)->makePartial();
    $job->shouldNotReceive('release');

    $reached = false;

    (new ThrottleEspn)->handle($job, function () use (&$reached) {
        $reached = true;
    });

    expect($reached)->toBeTrue();
});
