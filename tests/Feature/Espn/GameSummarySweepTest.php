<?php

use App\Jobs\FetchGameSummary;
use App\Models\Game;
use App\Models\GameSummary;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

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
