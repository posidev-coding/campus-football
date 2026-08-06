<?php

use App\Events\GameScoreChanged;
use App\Events\GameWentFinal;
use App\Jobs\FetchGameSummary;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Services\Espn\Sync\SyncGames;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * The pick'em subscription points. Contest scoring needs exactly what the
 * live tier already writes — scores and status — so a future recompute
 * listener subscribes to these instead of polling. They fire only on real
 * transitions: a no-change pass and a first insert are both silent.
 */

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);

    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
    $this->week = Week::create([
        'season_id' => $this->season->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => '2025-09-23', 'end_date' => '2025-09-29',
    ]);

    Team::factory()->create(['id' => 61, 'display_name' => 'Georgia']);
    Team::factory()->create(['id' => 333, 'display_name' => 'Alabama']);

    Event::fake([GameScoreChanged::class, GameWentFinal::class]);

    /*
     * One closure-backed stub, registered once: sequential Http::fake calls
     * STACK, and the first '*' registered keeps winning — so a test that
     * fakes a new payload per pass would silently replay the first one and
     * never produce a dirty row. The closure reads whatever the last
     * syncGameEvents() put here.
     */
    Http::fake(['*scoreboard*' => fn () => Http::response(test()->scoreboardPayload)]);
});

function gameEventsPayload(int $homeScore, int $awayScore, bool $completed, string $state): array
{
    return ['events' => [[
        'id' => '401',
        'date' => '2025-09-27T19:30Z',
        'name' => 'Alabama at Georgia',
        'shortName' => 'BAMA @ UGA',
        'season' => ['year' => 2025, 'type' => 2],
        'status' => [
            'period' => 3,
            'displayClock' => '8:12',
            'type' => ['state' => $state, 'completed' => $completed, 'shortDetail' => $completed ? 'Final' : '8:12 - 3rd'],
        ],
        'competitions' => [[
            'neutralSite' => false,
            'conferenceCompetition' => true,
            'competitors' => [
                ['id' => '61', 'homeAway' => 'home', 'score' => (string) $homeScore],
                ['id' => '333', 'homeAway' => 'away', 'score' => (string) $awayScore],
            ],
        ]],
    ]]];
}

function syncGameEvents(int $homeScore, int $awayScore, bool $completed = false, string $state = 'in'): void
{
    test()->scoreboardPayload = gameEventsPayload($homeScore, $awayScore, $completed, $state);

    app(SyncGames::class)->week(test()->week);
}

it('fires GameScoreChanged with the new scores when a live score moves', function () {
    syncGameEvents(7, 0);
    syncGameEvents(14, 0);

    Event::assertDispatched(GameScoreChanged::class, fn (GameScoreChanged $event) => $event->gameId === 401
        && $event->homeScore === 14
        && $event->awayScore === 0
        && $event->status === 'in');
});

it('fires GameWentFinal and still queues the forced summary fetch on completion', function () {
    Queue::fake();

    syncGameEvents(14, 10);
    syncGameEvents(31, 17, completed: true, state: 'post');

    Event::assertDispatched(GameWentFinal::class, fn (GameWentFinal $event) => $event->gameId === 401);
    Queue::assertPushed(FetchGameSummary::class, fn (FetchGameSummary $job) => $job->gameId === 401 && $job->force === true);
    Queue::assertPushedOn('live', FetchGameSummary::class);
});

it('fires neither event on a pass that changed nothing', function () {
    // The live tier re-reads the whole day every minute; most games have not
    // moved since the last pass, and a listener must not hear about them.
    syncGameEvents(7, 0);

    Event::fake([GameScoreChanged::class, GameWentFinal::class]);

    syncGameEvents(7, 0);

    Event::assertNotDispatched(GameScoreChanged::class);
    Event::assertNotDispatched(GameWentFinal::class);
});

it('fires no score event when a game row is first created', function () {
    // A season backfill creating 950 rows is not 950 score changes.
    syncGameEvents(7, 0);

    Event::assertNotDispatched(GameScoreChanged::class);
});
