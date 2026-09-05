<?php

use App\Models\Article;
use App\Models\FeedRun;
use App\Models\Team;
use App\Models\User;
use App\Services\Espn\Sync\SyncNews;
use Illuminate\Support\Facades\Http;

/*
 * `cfb:news:followed` exists so the twice-daily sweep can be SEEN. It was two
 * `Schedule::call()` closures, and a closure carries no TracksFeedRun — both
 * entries reported `last_status: null` on Sync Health permanently, whether the
 * sweep ran, failed, or synced nothing at all. The general feed stayed fresh
 * the whole time, so the coverage row said everything was fine while the
 * per-team feeds behind every follow could have been dead for a season.
 *
 * So these hold the ledger row itself, in both directions: the pass that does
 * work, and the pass that finds nothing to do.
 */

function followedNewsArticle(int $espnId, int $teamId, string $headline): array
{
    return [
        'id' => $espnId,
        'headline' => $headline,
        'published' => '2026-09-04T10:57:23Z',
        'categories' => [['type' => 'team', 'teamId' => $teamId]],
    ];
}

it('records a run, its articles and one request per followed team', function () {
    $tennessee = Team::factory()->create(['id' => 2633, 'display_name' => 'Tennessee Volunteers']);
    $alabama = Team::factory()->create(['id' => 333, 'display_name' => 'Alabama Crimson Tide']);

    // Two followers of the SAME team plus a second team: the sweep reads a
    // distinct team list, so this is three follows and two requests.
    User::factory()->create()->followedTeams()->attach($tennessee->id, ['position' => 1]);
    $second = User::factory()->create();
    $second->followedTeams()->attach($tennessee->id, ['position' => 1]);
    $second->followedTeams()->attach($alabama->id, ['position' => 2]);

    Http::fake([
        '*team=2633*' => Http::response(['articles' => [
            followedNewsArticle(9001, 2633, 'Vols climb the polls'),
            followedNewsArticle(9002, 2633, 'Neyland gets a new video board'),
        ]]),
        '*team=333*' => Http::response(['articles' => [
            followedNewsArticle(9003, 333, 'Tide sign a five-star'),
        ]]),
    ]);

    $this->artisan('cfb:news:followed')
        ->expectsOutputToContain('Synced 3 followed-team articles.')
        ->assertSuccessful();

    $run = FeedRun::latestFor('news:followed');

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(FeedRun::COMPLETE)
        ->and($run->records)->toBe(3)
        // Cost tracks interest: one request per FOLLOWED team, never 136, and
        // never one per follower either.
        ->and($run->requests)->toBe(2)
        ->and($run->season_year)->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and(Article::count())->toBe(3);
});

it('still writes a row when nobody follows anything', function () {
    /*
     * The whole point of the card. A pass with no followed teams spends no
     * request and writes no article, and if it also wrote no ledger row it
     * would be indistinguishable from a sweep that never ran — which is
     * exactly the state this command replaced.
     */
    Http::fake();

    $this->artisan('cfb:news:followed')
        ->expectsOutputToContain('Synced 0 followed-team articles.')
        ->assertSuccessful();

    Http::assertNothingSent();

    $run = FeedRun::latestFor('news:followed');

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(FeedRun::COMPLETE)
        ->and($run->records)->toBe(0)
        ->and($run->requests)->toBe(0);
});

it('records a failed row and rethrows, rather than swallowing the fault', function () {
    /*
     * Recording is bookkeeping, never a rescue: the scheduler's exit code and
     * Cloud's failure signal both still have to mean what they meant. Thrown
     * from the sync rather than from the HTTP fake on purpose — EspnClient
     * catches its own transport failures and returns null, so a faked
     * connection error never reaches the trait.
     */
    $this->mock(SyncNews::class)
        ->shouldReceive('followed')
        ->once()
        ->andThrow(new RuntimeException('ESPN fell over'));

    expect(fn () => $this->artisan('cfb:news:followed')->run())
        ->toThrow(RuntimeException::class);

    $run = FeedRun::latestFor('news:followed');

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(FeedRun::FAILED)
        ->and($run->error)->toContain('ESPN fell over')
        ->and($run->finished_at)->not->toBeNull();
});
