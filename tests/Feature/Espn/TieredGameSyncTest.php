<?php

use App\Jobs\FetchGameSummary;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Services\Espn\Sync\SyncGames;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * The sync tiers exist so the scheduler can spend the minimum that keeps data
 * correct. v3 ran a full games feed every five minutes on Saturdays — 70-110
 * sequential requests per run — plus one live ESPN call per page view per
 * viewer. These tests pin the cost, not just the correctness.
 */

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);

    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);
    $this->week = Week::create([
        'season_id' => $this->season->id,
        'number' => 5,
        'name' => 'Week 5',
        'start_date' => '2025-09-23',
        'end_date' => '2025-09-29',
    ]);

    Team::factory()->create(['id' => 61, 'display_name' => 'Georgia']);
    Team::factory()->create(['id' => 333, 'display_name' => 'Alabama']);
});

function scoreboardEvent(int $id, string $date, int $homeScore, int $awayScore, bool $completed = true, string $state = 'post'): array
{
    return [
        'id' => (string) $id,
        'date' => $date,
        'name' => 'Alabama at Georgia',
        'shortName' => 'BAMA @ UGA',
        'season' => ['year' => 2025, 'type' => 2],
        'status' => [
            'period' => 4,
            'displayClock' => '0:00',
            'type' => ['state' => $state, 'completed' => $completed, 'shortDetail' => 'Final'],
        ],
        'competitions' => [[
            'neutralSite' => false,
            'conferenceCompetition' => true,
            'competitors' => [
                ['id' => '61', 'homeAway' => 'home', 'score' => (string) $homeScore, 'curatedRank' => ['current' => 1]],
                ['id' => '333', 'homeAway' => 'away', 'score' => (string) $awayScore, 'curatedRank' => ['current' => 99]],
            ],
        ]],
    ];
}

function fakeScoreboard(array $events): void
{
    Http::fake(['*scoreboard*' => Http::response(['events' => $events])]);
}

it('syncs a week in a single request', function () {
    /*
     * Queue faked deliberately. A finished game now dispatches its own summary
     * fetch, and the test suite runs on the `sync` driver — so without this the
     * job would execute INLINE and this would count its request too. In
     * production the queue is redis and the dispatch is asynchronous, which is
     * the whole point: the live tier's one-request budget is preserved.
     */
    Queue::fake();

    fakeScoreboard([scoreboardEvent(401, '2025-09-27T19:30Z', 31, 17)]);

    expect(app(SyncGames::class)->week($this->week))->toBe(1);

    Http::assertSentCount(1);
});

/**
 * A game the way it actually arrives: scheduled first, then played.
 *
 * The transition is the whole signal, so a test about finishing has to
 * create the row BEFORE it is complete — a row that shows up already
 * finished is a backfill, and deliberately queues nothing.
 */
function kickOffThenFinish(): void
{
    /*
     * ONE fake, as a sequence. Successive Http::fake() calls STACK and the
     * first registered pattern keeps answering, so faking the finished
     * payload after the live one silently replays the live one and the game
     * never transitions — which is a test that proves nothing.
     */
    $finished = ['events' => [scoreboardEvent(401, '2025-09-27T19:30Z', 31, 17)]];

    Http::fake(['*scoreboard*' => Http::sequence()
        ->push(['events' => [scoreboardEvent(401, '2025-09-27T19:30Z', 14, 10, completed: false, state: 'in')]])
        ->push($finished)
        // Every later pass re-reads the finished game, which is what the
        // live tier does for the rest of the day.
        ->whenEmpty(Http::response($finished)),
    ]);

    app(SyncGames::class)->week(test()->week);
    app(SyncGames::class)->week(test()->week);
}

it('queues a box score the moment a game finishes', function () {
    /*
     * The day-to-day win. A nightly sweep meant an 11pm Saturday final had no
     * box score until 05:00 Sunday — exactly the window people want to look at
     * it. The live tier already detects the transition, so this costs one
     * queued job per game per season rather than a scan.
     */
    Queue::fake();

    kickOffThenFinish();

    Queue::assertPushed(FetchGameSummary::class, fn (FetchGameSummary $job) => $job->gameId === 401
        // Forced past the staleness check, and on `live` so a Saturday's
        // finals never wait behind a draining backfill.
        && $job->force === true);
    Queue::assertPushedOn('live', FetchGameSummary::class);
});

it('does not re-queue a box score for a game that was already final', function () {
    // Only the TRANSITION matters. Re-reading a finished game every minute for
    // the rest of the day must not queue the same fetch over and over.
    Queue::fake();

    kickOffThenFinish();
    app(SyncGames::class)->week($this->week);

    Queue::assertPushed(FetchGameSummary::class, 1);
});

it('does not queue box scores for a backfill of already-finished games', function () {
    /*
     * A game is always scheduled before it is played, so a row arriving
     * already completed is history being imported rather than a whistle.
     * Seeding six seasons this way once queued 4,844 fetches onto the `live`
     * queue — which is the queue a Saturday depends on, and exactly what
     * splitting the queues was meant to protect. Backfills go through
     * `cfb:summaries --missing`, which queues them on `backfill`.
     */
    Queue::fake();

    fakeScoreboard([scoreboardEvent(401, '2025-09-27T19:30Z', 31, 17)]);
    app(SyncGames::class)->week($this->week);

    Queue::assertNotPushed(FetchGameSummary::class);
});

it('does not queue a box score for a game still in progress', function () {
    Queue::fake();

    $event = scoreboardEvent(401, '2025-09-27T19:30Z', 14, 10);
    $event['status']['type']['completed'] = false;
    $event['status']['type']['state'] = 'in';

    fakeScoreboard([$event]);
    app(SyncGames::class)->week($this->week);

    Queue::assertNotPushed(FetchGameSummary::class);
});

it('writes nothing on a re-sync when no game has changed', function () {
    fakeScoreboard([scoreboardEvent(401, '2025-09-27T19:30Z', 31, 17)]);

    app(SyncGames::class)->week($this->week);
    $touchedAt = Game::whereKey(401)->value('updated_at');

    // Second pass over identical data.
    $changed = app(SyncGames::class)->week($this->week);

    expect($changed)->toBe(0)
        ->and(Game::whereKey(401)->value('updated_at')->eq($touchedAt))->toBeTrue();
});

it('writes only the games that actually moved', function () {
    // A sequence, not two Http::fake() calls — a second fake() adds a stub
    // rather than replacing the first, so the original would keep matching.
    Http::fake([
        '*scoreboard*' => Http::sequence()
            ->push(['events' => [
                scoreboardEvent(401, '2025-09-27T19:30Z', 14, 7, completed: false, state: 'in'),
                scoreboardEvent(402, '2025-09-27T23:00Z', 21, 20),
            ]])
            ->push(['events' => [
                // Only 401's score moves.
                scoreboardEvent(401, '2025-09-27T19:30Z', 21, 7, completed: false, state: 'in'),
                scoreboardEvent(402, '2025-09-27T23:00Z', 21, 20),
            ]]),
    ]);

    expect(app(SyncGames::class)->week($this->week))->toBe(2);

    expect(app(SyncGames::class)->week($this->week))->toBe(1)
        ->and(Game::whereKey(401)->value('home_score'))->toBe(21)
        ->and(Game::whereKey(402)->value('home_score'))->toBe(21);
});

it('makes no ESPN request at all when nothing is live', function () {
    Game::factory()->finished()->create(['season_id' => $this->season->id]);

    Http::fake();

    expect(app(SyncGames::class)->live())->toBe(0);

    // The guard is a database check, so the live tier is free out of season and
    // between games.
    Http::assertNothingSent();
});

it('refreshes every live game in one request, however many there are', function () {
    // Three games in progress.
    foreach ([501, 502, 503] as $id) {
        Game::factory()->create([
            'id' => $id,
            'season_id' => $this->season->id,
            'status' => 'in',
            'completed' => false,
        ]);
    }

    fakeScoreboard([scoreboardEvent(501, now()->toIso8601String(), 10, 3, completed: false, state: 'in')]);

    app(SyncGames::class)->live();

    // This is the whole point: N live games, and N concurrent viewers, cost one
    // request between them. v3 cost one request per viewer per 15 seconds.
    Http::assertSentCount(1);
});

it('stores the ET day of week so Saturday slating can be indexed', function () {
    // 2025-09-28T00:30Z is Saturday 8:30pm Eastern — a Sunday date in UTC.
    fakeScoreboard([scoreboardEvent(401, '2025-09-28T00:30Z', 31, 17)]);

    app(SyncGames::class)->week($this->week);

    expect(Game::whereKey(401)->value('kickoff_day'))->toBe('Sat')
        ->and(Game::slateEligible()->count())->toBe(1);
});

it('stores an unranked team as null rather than ESPN 99 sentinel', function () {
    fakeScoreboard([scoreboardEvent(401, '2025-09-27T19:30Z', 31, 17)]);

    app(SyncGames::class)->week($this->week);

    $game = Game::whereKey(401)->sole();

    expect($game->home_rank)->toBe(1)
        ->and($game->away_rank)->toBeNull();
});

describe('unannounced fixtures', function () {
    /*
     * ESPN publishes every bowl and playoff game months ahead as "TBD at TBD",
     * and it does NOT use a null competitor for that — it sends a real
     * competitor whose team id is NEGATIVE (-1 home, -2 away).
     *
     * `games.home_team_id` is `mediumint unsigned` with a foreign key to
     * `teams`, so storing that verbatim fails outright and the whole postseason
     * slate goes missing. Same rule as the box-score pseudo-athletes: ESPN uses
     * non-positive ids for things that are not real entities.
     */
    it('stores a TBD fixture with null teams rather than negative ids', function () {
        Queue::fake();

        $event = scoreboardEvent(402, '2025-09-27T19:30Z', 0, 0, completed: false, state: 'pre');
        $event['name'] = 'TBD at TBD';
        $event['competitions'][0]['competitors'] = [
            ['id' => '-1', 'homeAway' => 'home', 'score' => '0'],
            ['id' => '-2', 'homeAway' => 'away', 'score' => '0'],
        ];
        $event['competitions'][0]['notes'] = [['headline' => 'Boca Raton Bowl']];

        fakeScoreboard([$event]);

        expect(app(SyncGames::class)->week($this->week))->toBe(1);

        $game = Game::find(402);

        expect($game)->not->toBeNull()
            ->and($game->home_team_id)->toBeNull()
            ->and($game->away_team_id)->toBeNull()
            // The point of storing it at all: the date, venue and bowl name are
            // known long before the teams are.
            ->and($game->note)->toBe('Boca Raton Bowl');
    });

    it('keeps a real team id', function () {
        Queue::fake();

        fakeScoreboard([scoreboardEvent(403, '2025-09-27T19:30Z', 31, 17)]);

        app(SyncGames::class)->week($this->week);

        expect(Game::find(403)->home_team_id)->toBe(61)
            ->and(Game::find(403)->away_team_id)->toBe(333);
    });
});

it('does not let one unstorable game take out the rest of the window', function () {
    /*
     * This is the failure that hid an entire postseason. A game referencing a
     * team we do not carry throws against the foreign key; without isolation
     * the exception aborted the whole request, so every event after it in the
     * payload was silently lost — the 2026 season stopped at the first
     * conference championship and no bowl was ever stored.
     */
    Queue::fake();

    $bad = scoreboardEvent(500, '2025-09-27T19:30Z', 0, 0);
    $bad['competitions'][0]['competitors'][0]['id'] = '99999999';

    fakeScoreboard([
        $bad,
        scoreboardEvent(501, '2025-09-27T23:00Z', 21, 14),
    ]);

    expect(app(SyncGames::class)->week($this->week))->toBe(1);

    expect(Game::find(500))->toBeNull()
        // The one that matters: the good game after it still landed.
        ->and(Game::find(501))->not->toBeNull();
});
