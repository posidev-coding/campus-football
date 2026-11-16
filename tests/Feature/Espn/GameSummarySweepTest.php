<?php

use App\Jobs\FetchGameSummary;
use App\Models\FeedRun;
use App\Models\Game;
use App\Models\GameSummary;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A queue fake whose store stalls for the listed games: push() throws for
 * those, and records every other push exactly as Queue::fake() would.
 *
 * @param  list<int>  $stalledGameIds
 */
function stallQueueFor(array $stalledGameIds): QueueFake
{
    $fake = new class(app(), [], Queue::getFacadeRoot()) extends QueueFake
    {
        /** @var list<int> */
        public array $stalled = [];

        public function push($job, $data = '', $queue = null)
        {
            if ($job instanceof FetchGameSummary && in_array($job->gameId, $this->stalled, true)) {
                throw new RuntimeException("Queue store stalled on game {$job->gameId}");
            }

            return parent::push($job, $data, $queue);
        }
    };

    $fake->stalled = $stalledGameIds;
    Queue::swap($fake);

    return $fake;
}

/*
 * The gameday sweep: every in-progress game with a stale box score gets one
 * queued refresh, so unwatched games stay hydrated too. The command's SQL
 * filter mirrors SyncGameSummary::isStale(); the job's own re-check is the
 * authority if they ever drift.
 */

beforeEach(function () {
    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
    $this->week = Week::create([
        'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
    ]);

    Team::factory()->create(['id' => 61, 'display_name' => 'Georgia']);
    Team::factory()->create(['id' => 333, 'display_name' => 'Alabama']);

    // kickoff PINNED: GameFactory randomizes it across four months, which
    // drifts into date-window queries and shifts the faker sequence for
    // every test that runs after this file.
    $this->makeGame = fn (array $attributes) => Game::factory()->create($attributes + [
        'season_id' => $this->season->id, 'week_id' => $this->week->id,
        'home_team_id' => 61, 'away_team_id' => 333,
        'kickoff_at' => '2025-09-27 19:30:00',
    ]);
});

it('queues a refresh only for live games whose summary is due one', function () {
    Queue::fake();

    // Due: live, box score a lifetime old.
    $stale = ($this->makeGame)(['completed' => false, 'status' => 'in']);
    GameSummary::create(['game_id' => $stale->id, 'is_final' => false, 'synced_at' => now()->subMinutes(5)]);

    // Due: live and never summarized at all — the unwatched-game case the
    // sweep exists for.
    $never = ($this->makeGame)(['completed' => false, 'status' => 'in']);

    // Not due: live but synced seconds ago.
    $fresh = ($this->makeGame)(['completed' => false, 'status' => 'in']);
    GameSummary::create(['game_id' => $fresh->id, 'is_final' => false, 'synced_at' => now()]);

    // Not due: finished (the just-final dispatch owns it) and pregame.
    ($this->makeGame)(['completed' => true, 'status' => 'post']);
    ($this->makeGame)(['completed' => false, 'status' => 'pre']);

    $this->artisan('cfb:summaries:live')
        ->expectsOutputToContain('Queued 2')
        ->assertSuccessful();

    Queue::assertPushed(FetchGameSummary::class, 2);
    Queue::assertPushed(FetchGameSummary::class, fn (FetchGameSummary $job) => $job->gameId === $stale->id && $job->force === false);
    Queue::assertPushed(FetchGameSummary::class, fn (FetchGameSummary $job) => $job->gameId === $never->id);
    Queue::assertPushedOn('live', FetchGameSummary::class);
});

it('queues nothing when no games are live', function () {
    // The scheduler runs this every two minutes inside the live window; a
    // quiet Tuesday must cost one cheap query and zero dispatches.
    Queue::fake();

    ($this->makeGame)(['completed' => true, 'status' => 'post']);

    $this->artisan('cfb:summaries:live')
        ->expectsOutputToContain('Queued 0')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('dispatches individually, never as a batch', function () {
    // Batched jobs skip ShouldBeUnique locks — and uniqueness is the first
    // layer of the guarantee that the sweep and a page full of viewers
    // cannot stack fetches for one game.
    Bus::fake();

    ($this->makeGame)(['completed' => false, 'status' => 'in']);

    $this->artisan('cfb:summaries:live')->assertSuccessful();

    Bus::assertNothingBatched();
});

it('tries every game, then fails the run naming the one whose push stalled', function () {
    // A dispatch is a write to the queue store. Inside a bare each() a throw
    // on game two of three dropped game three as well. Now every game is tried
    // first, and only then does the run fail, the same as SyncGames after a
    // partial pass, so a game that stalls every tick is not hidden behind a
    // run marked complete.
    [$first, $stalled, $last] = collect(range(1, 3))
        ->map(fn () => ($this->makeGame)(['completed' => false, 'status' => 'in']))
        ->all();

    stallQueueFor([$stalled->id]);

    expect(fn () => $this->artisan('cfb:summaries:live')->run())
        ->toThrow(RuntimeException::class, '1 of 3 live summary dispatches failed (2 queued)');

    Queue::assertPushed(FetchGameSummary::class, 2);
    Queue::assertPushed(FetchGameSummary::class, fn (FetchGameSummary $job) => $job->gameId === $first->id);
    Queue::assertPushed(FetchGameSummary::class, fn (FetchGameSummary $job) => $job->gameId === $last->id);
    Queue::assertNotPushed(FetchGameSummary::class, fn (FetchGameSummary $job) => $job->gameId === $stalled->id);

    $run = FeedRun::latestFor('summaries:live');

    expect($run->status)->toBe(FeedRun::FAILED)
        ->and($run->error)->toContain("game {$stalled->id}: Queue store stalled");
});

it('exits non-zero from the console when a dispatch failed', function () {
    // The scheduler and the Cloud dashboard read the process exit code, which
    // the console kernel derives from the thrown exception. Driven through the
    // kernel itself, because the test helper rethrows instead of exiting.
    $stalled = ($this->makeGame)(['completed' => false, 'status' => 'in']);
    $other = ($this->makeGame)(['completed' => false, 'status' => 'in']);

    stallQueueFor([$stalled->id]);

    $exitCode = $this->app->make(Kernel::class)->handle(
        new ArrayInput(['command' => 'cfb:summaries:live']),
        new BufferedOutput,
    );

    expect($exitCode)->not->toBe(0);

    Queue::assertPushed(FetchGameSummary::class, fn (FetchGameSummary $job) => $job->gameId === $other->id);
});

it('releases the unique lock a failed push took, so the next tick queues the game', function () {
    // PendingDispatch takes the ShouldBeUnique lock (300s) before it pushes.
    // Left held, the next two ticks would skip the game as already queued.
    $stalled = ($this->makeGame)(['completed' => false, 'status' => 'in']);

    stallQueueFor([$stalled->id]);

    expect(fn () => $this->artisan('cfb:summaries:live')->run())->toThrow(RuntimeException::class);

    // The store recovers by the next tick.
    Queue::fake();

    $this->artisan('cfb:summaries:live')
        ->expectsOutputToContain('Queued 1')
        ->assertSuccessful();

    Queue::assertPushed(FetchGameSummary::class, fn (FetchGameSummary $job) => $job->gameId === $stalled->id);
});

it('reports every failed dispatch to the exception handler', function () {
    $reported = [];
    $this->app->make(ExceptionHandler::class)
        ->reportable(function (RuntimeException $e) use (&$reported) {
            $reported[] = $e->getMessage();

            return false;
        });

    $stalled = ($this->makeGame)(['completed' => false, 'status' => 'in']);
    ($this->makeGame)(['completed' => false, 'status' => 'in']);

    stallQueueFor([$stalled->id]);

    expect(fn () => $this->artisan('cfb:summaries:live')->run())->toThrow(RuntimeException::class);

    expect($reported)->toBe(["Queue store stalled on game {$stalled->id}"]);
});

it('fails the run when every dispatch failed', function () {
    $games = collect(range(1, 2))
        ->map(fn () => ($this->makeGame)(['completed' => false, 'status' => 'in']));

    stallQueueFor($games->pluck('id')->all());

    expect(fn () => $this->artisan('cfb:summaries:live')->run())
        ->toThrow(RuntimeException::class, '2 of 2 live summary dispatches failed (0 queued)');

    Queue::assertNothingPushed();

    $run = FeedRun::latestFor('summaries:live');

    expect($run->status)->toBe(FeedRun::FAILED)
        ->and($run->error)->toContain("game {$games[0]->id}")
        ->and($run->error)->toContain("game {$games[1]->id}");
});

it('records a clean run as complete', function () {
    Queue::fake();

    ($this->makeGame)(['completed' => false, 'status' => 'in']);

    $this->artisan('cfb:summaries:live')->assertSuccessful();

    $run = FeedRun::latestFor('summaries:live');

    expect($run->status)->toBe(FeedRun::COMPLETE)
        ->and($run->records)->toBe(1)
        ->and($run->error)->toBeNull();
});
