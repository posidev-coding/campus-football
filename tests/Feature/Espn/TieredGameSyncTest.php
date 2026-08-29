<?php

use App\Jobs\FetchGameSummary;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Services\Espn\Sync\SyncGames;
use Illuminate\Support\Facades\DB;
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

function scoreboardEvent(int $id, string $date, int $homeScore, int $awayScore, bool $completed = true, string $state = 'post', ?array $situation = null): array
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
        'competitions' => [array_filter([
            'neutralSite' => false,
            'conferenceCompetition' => true,
            'situation' => $situation,
            'competitors' => [
                ['id' => '61', 'homeAway' => 'home', 'score' => (string) $homeScore, 'curatedRank' => ['current' => 1]],
                ['id' => '333', 'homeAway' => 'away', 'score' => (string) $awayScore, 'curatedRank' => ['current' => 99]],
            ],
        ], fn ($value) => $value !== null)],
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

it('reads the payload through per-payload maps, never a query per game', function () {
    /*
     * The live tier re-reads the whole day every minute; per-event
     * firstOrNew/updateOrCreate was hundreds of mostly-no-op queries a
     * minute all Saturday. A quiet pass costs the payload-level reads —
     * seasons, weeks, games, venues, odds — and nothing per game.
     * Break-back: reverting the preload maps pushes this past the bound.
     */
    fakeScoreboard([
        scoreboardEvent(401, '2025-09-27T19:30Z', 31, 17),
        scoreboardEvent(402, '2025-09-27T23:00Z', 21, 20),
        scoreboardEvent(403, '2025-09-28T00:00Z', 14, 10),
    ]);

    app(SyncGames::class)->week($this->week);

    DB::enableQueryLog();
    app(SyncGames::class)->week($this->week);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(5);
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

describe('the live situation block', function () {
    /*
     * Possession, down and distance, red zone, timeouts, the last play. It
     * rides the scoreboard payload the live tier already fetches, so keeping
     * it costs nothing — and like everything on that tier it cannot be
     * re-observed after the fact.
     */
    it('stores the situation on a live game', function () {
        fakeScoreboard([scoreboardEvent(401, '2025-09-27T19:30Z', 14, 10, completed: false, state: 'in', situation: [
            'possession' => '61',
            'down' => 3,
            'distance' => 7,
            'yardLine' => 34,
            'shortDownDistanceText' => '3rd & 7',
            'downDistanceText' => '3rd & 7 at ALA 34',
            'isRedZone' => true,
            'lastPlay' => ['text' => 'Pass incomplete deep right.'],
            'homeTimeouts' => 2,
            'awayTimeouts' => 3,
        ])]);

        app(SyncGames::class)->week($this->week);

        $game = Game::whereKey(401)->sole();

        expect($game->possession_team_id)->toBe(61)
            ->and($game->down)->toBe(3)
            ->and($game->distance)->toBe(7)
            ->and($game->yard_line)->toBe(34)
            ->and($game->down_distance_text)->toBe('3rd & 7')
            ->and($game->is_red_zone)->toBeTrue()
            ->and($game->last_play_text)->toBe('Pass incomplete deep right.')
            ->and($game->home_timeouts)->toBe(2)
            ->and($game->away_timeouts)->toBe(3);
    });

    it('keeps the last situation when a live payload omits the block', function () {
        // A transient gap mid-game is "the feed returned nothing", and nulling
        // real data over it is the default-writing mistake. Only a game that
        // has left the in state clears.
        Http::fake(['*scoreboard*' => Http::sequence()
            ->push(['events' => [scoreboardEvent(401, '2025-09-27T19:30Z', 14, 10, completed: false, state: 'in', situation: [
                'possession' => '61', 'down' => 2, 'distance' => 4, 'shortDownDistanceText' => '2nd & 4',
            ])]])
            ->push(['events' => [scoreboardEvent(401, '2025-09-27T19:30Z', 14, 13, completed: false, state: 'in')]]),
        ]);

        app(SyncGames::class)->week($this->week);
        app(SyncGames::class)->week($this->week);

        $game = Game::whereKey(401)->sole();

        expect($game->down)->toBe(2)
            ->and($game->down_distance_text)->toBe('2nd & 4')
            ->and($game->possession_team_id)->toBe(61);
    });

    it('clears the situation when the game goes final', function () {
        // A final must not carry a frozen "3rd & 7" forever.
        Http::fake(['*scoreboard*' => Http::sequence()
            ->push(['events' => [scoreboardEvent(401, '2025-09-27T19:30Z', 14, 10, completed: false, state: 'in', situation: [
                'possession' => '61', 'down' => 3, 'distance' => 7, 'shortDownDistanceText' => '3rd & 7', 'isRedZone' => true,
            ])]])
            ->push(['events' => [scoreboardEvent(401, '2025-09-27T19:30Z', 31, 17)]]),
        ]);

        Queue::fake();

        app(SyncGames::class)->week($this->week);
        app(SyncGames::class)->week($this->week);

        $game = Game::whereKey(401)->sole();

        expect($game->down)->toBeNull()
            ->and($game->down_distance_text)->toBeNull()
            ->and($game->possession_team_id)->toBeNull()
            ->and($game->is_red_zone)->toBeFalse();
    });

    it('nulls a non-positive possession id rather than storing it', function () {
        // Same rule as competitor ids and box-score pseudo-athletes: ESPN's
        // non-positive ids are not real entities.
        fakeScoreboard([scoreboardEvent(401, '2025-09-27T19:30Z', 14, 10, completed: false, state: 'in', situation: [
            'possession' => '-1', 'down' => 1, 'distance' => 10,
        ])]);

        app(SyncGames::class)->week($this->week);

        expect(Game::whereKey(401)->sole()->possession_team_id)->toBeNull();
    });
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

describe('starting and holding the live window', function () {
    /*
     * The live tier is the ONLY minute-cadence thing in the schedule, so
     * whatever it declines to do is invisible for up to an hour — the next
     * `--tier=current` pass is the fallback. Both tests below are about the
     * tier declining to do anything at all.
     */

    function scoreboardDatesSent(): array
    {
        $dates = [];

        Http::assertSent(function ($request) use (&$dates): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $dates[] = $query['dates'] ?? null;

            return true;
        });

        return $dates;
    }

    it('starts live coverage for a game whose kickoff has passed', function () {
        /*
         * The deadlock this fixes. The guard used to be `inProgress()` alone,
         * which can only CONTINUE coverage: a game sits at `pre` until a
         * request says otherwise, and the tier refused to spend one until
         * something was already `in`. Measured in production on 2026-08-29 —
         * UNC at TCU was 10-10 in the second quarter on ESPN while
         * `cfb:games --tier=live` reported "0 changed, 0 requests", and every
         * `inProgress()`-gated consumer behind it (the box-score sweep, the
         * `live` queue, the gamecast) stayed empty with it.
         *
         * Break-back: restore the old guard and this asserts nothing sent.
         */
        $this->travelTo('2025-09-27 20:00:00');

        Game::factory()->create([
            'id' => 401,
            'season_id' => $this->season->id,
            // Kicked off half an hour ago; the feed has not been read since.
            'kickoff_at' => '2025-09-27 19:30:00',
            'status' => 'pre',
            'completed' => false,
        ]);

        fakeScoreboard([scoreboardEvent(401, '2025-09-27T19:30Z', 14, 10, completed: false, state: 'in')]);

        app(SyncGames::class)->live();

        Http::assertSentCount(1);

        expect(Game::whereKey(401)->value('status'))->toBe('in')
            ->and(Game::whereKey(401)->value('home_score'))->toBe(14);
    });

    it('stops presuming a game is live once the grace window closes', function () {
        /*
         * The bound on the other end. A postponed game that never leaves `pre`
         * must not hold the minute tier open for the rest of the season — the
         * whole reason this guard is a database check is that a quiet tick is
         * free.
         */
        $this->travelTo('2025-09-28 08:00:00');

        Game::factory()->create([
            'id' => 401,
            'season_id' => $this->season->id,
            'kickoff_at' => '2025-09-27 19:30:00',
            'status' => 'pre',
            'completed' => false,
        ]);

        Http::fake();

        expect(app(SyncGames::class)->live())->toBe(0);

        Http::assertNothingSent();
    });

    it('asks for the late game\'s own Eastern date after midnight', function () {
        /*
         * ESPN buckets an event by its EASTERN date: a 22:30 ET Saturday
         * kickoff lives on `dates=20250927`, and `dates=20250928` returns zero
         * events — verified against the feed on 2026-08-29 with a real
         * 2025-11-29 night game.
         *
         * The live window runs to 03:00 precisely so a West Coast night game
         * keeps updating, but `day()` rolled to the new ET date at midnight and
         * fetched an empty payload — freezing the score of exactly the game the
         * window was extended for. The date has to come from the GAME.
         */
        // 01:00 ET Sunday. The game kicked at 22:30 ET Saturday and is live.
        $this->travelTo('2025-09-28 05:00:00');

        Game::factory()->create([
            'id' => 401,
            'season_id' => $this->season->id,
            'kickoff_at' => '2025-09-28 02:30:00',
            'status' => 'in',
            'completed' => false,
        ]);

        fakeScoreboard([scoreboardEvent(401, '2025-09-28T02:30Z', 21, 17, completed: false, state: 'in')]);

        app(SyncGames::class)->live();

        // Saturday, not Sunday. Break-back: `day()` here asks for 20250928.
        expect(scoreboardDatesSent())->toBe(['20250927']);
    });

    it('covers both Eastern dates in one request when the slate straddles midnight', function () {
        // A late Saturday game still playing while an early Sunday game kicks.
        // Two scoreboard days, still ONE request — the budget is the point.
        $this->travelTo('2025-09-28 05:00:00');

        Game::factory()->create([
            'id' => 401,
            'season_id' => $this->season->id,
            'kickoff_at' => '2025-09-28 02:30:00',
            'status' => 'in',
            'completed' => false,
        ]);

        Game::factory()->create([
            'id' => 402,
            'season_id' => $this->season->id,
            'kickoff_at' => '2025-09-28 04:45:00',
            'status' => 'in',
            'completed' => false,
        ]);

        fakeScoreboard([scoreboardEvent(401, '2025-09-28T02:30Z', 21, 17, completed: false, state: 'in')]);

        app(SyncGames::class)->live();

        Http::assertSentCount(1);
        expect(scoreboardDatesSent())->toBe(['20250927-20250928']);
    });
});
