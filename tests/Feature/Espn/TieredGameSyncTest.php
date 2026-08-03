<?php

use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Services\Espn\Sync\SyncGames;
use Illuminate\Support\Facades\Http;

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
    fakeScoreboard([scoreboardEvent(401, '2025-09-27T19:30Z', 31, 17)]);

    expect(app(SyncGames::class)->week($this->week))->toBe(1);

    Http::assertSentCount(1);
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
