<?php

use App\Enums\GamedayStatus;
use App\Models\FeedRun;
use App\Models\Game;
use App\Models\GamedayWeek;
use App\Models\Season;
use App\Models\Team;
use App\Models\Venue;
use Illuminate\Support\Facades\Http;

/*
 * `cfb:gameday` runs five mornings a week and is expected to do nothing on
 * most of them. These hold the two halves of that: it resolves the week when
 * the feed has caught up, and it costs nothing — and breaks nothing — when it
 * has not.
 */

function gamedayFeedPayload(string $cutoff = '2026-09-05T09:00:00', string $location = 'Baton Rouge, LA'): array
{
    return ['matchups' => [[
        'cutoffTime' => $cutoff,
        'location' => $location,
        'prefix' => 'Week 1 Live from',
        'map' => ['address' => 'Norman Oklahoma'],
    ]]];
}

beforeEach(function () {
    $this->season = Season::factory()->create([
        'year' => 2026,
        'type' => Season::REGULAR,
        'start_date' => '2026-08-24',
        'end_date' => '2026-12-14',
    ]);

    $this->lsu = Team::factory()->create(['display_name' => 'LSU Tigers']);
    $venue = Venue::create(['id' => 99001, 'name' => 'Tiger Stadium', 'city' => 'Baton Rouge', 'state' => 'LA']);

    $this->game = Game::factory()->create([
        'season_id' => $this->season->id,
        'venue_id' => $venue->id,
        'home_team_id' => $this->lsu->id,
        'kickoff_at' => '2026-09-05 19:30:00',
        'neutral_site' => false,
    ]);

    // Wednesday of that week: inside the season, and the command's own window.
    $this->travelTo('2026-09-02 09:07');
});

it('resolves the Saturday and proposes it, without trusting the payload', function () {
    Http::fake(['*' => Http::response(gamedayFeedPayload())]);

    $this->artisan('cfb:gameday')->assertSuccessful();

    $week = GamedayWeek::sole();

    expect($week->status)->toBe(GamedayStatus::Proposed)
        ->and($week->saturday->toDateString())->toBe('2026-09-05')
        ->and($week->city)->toBe('Baton Rouge')
        ->and($week->site)->toBe('Tiger Stadium')
        ->and($week->game_id)->toBe($this->game->id)
        ->and($week->team_id)->toBe($this->lsu->id)
        // The venue and the host come from OUR data. The payload's own map
        // block says Norman, Oklahoma, and none of it reached the row.
        ->and($week->payload_hash)->toHaveLength(64);
});

it('writes a ledger row so the schedule screen can see it ran', function () {
    // A command that gains trackRun() needs a line in SyncSchedule::ledgerKey()
    // or its row renders permanently grey.
    Http::fake(['*' => Http::response(gamedayFeedPayload())]);

    $this->artisan('cfb:gameday')->assertSuccessful();

    expect(FeedRun::latestFor('gameday')?->status)->toBe(FeedRun::COMPLETE);
});

it('stops for the week once the Saturday resolves, without asking again', function () {
    /*
     * The reason a normal week costs one or two runs of five. If this ever
     * regresses it will not look broken — it will look like a feed being
     * polled five times to be told the same thing.
     */
    GamedayWeek::factory()->proposed()->create(['season_year' => 2026, 'saturday' => '2026-09-05']);

    Http::preventStrayRequests();

    $this->artisan('cfb:gameday')->assertSuccessful();

    Http::assertNothingSent();
});

it('records not-yet-announced when the feed has not caught up', function () {
    // Its stale rows are hidden by booleans rather than removed, so there is
    // always a most-recent matchup sitting there looking answerable.
    Http::fake(['*' => Http::response(gamedayFeedPayload('2026-08-29T09:00:00'))]);

    $this->artisan('cfb:gameday')->assertSuccessful();

    expect(GamedayWeek::sole()->status)->toBe(GamedayStatus::Unknown);
});

it('records not-yet-announced when the place contradicts our data', function () {
    Http::fake(['*' => Http::response(gamedayFeedPayload(location: 'Norman, OK'))]);

    $this->artisan('cfb:gameday')->assertSuccessful();

    expect(GamedayWeek::sole()->status)->toBe(GamedayStatus::Unknown)
        ->and(GamedayWeek::sole()->site)->toBeNull();
});

it('never downgrades a site it already has when a later check fails', function () {
    /*
     * The no-defaults rule aimed at our own earlier work rather than at
     * somebody else's feed: Monday resolved the week, Thursday's feed is
     * down, and Monday's answer is still the best one anybody has.
     */
    $week = GamedayWeek::factory()->proposed()->create([
        'season_year' => 2026,
        'saturday' => '2026-09-05',
        'checked_at' => now()->subDays(3),
    ]);

    Http::fake(['*' => Http::response('', 503)]);

    $this->artisan('cfb:gameday', ['--force' => true])->assertSuccessful();

    $week->refresh();

    expect($week->status)->toBe(GamedayStatus::Proposed)
        ->and($week->site)->toBe('LSU')
        ->and($week->checked_at->isToday())->toBeTrue();
});

it('looks forward to the coming Saturday on the mornings ESPN announces one', function () {
    /*
     * THE DIVERGENCE FROM THE PICK'EM CLOCK. Cadence::currentSaturday() runs
     * a Tuesday-through-Monday week, so on Sunday and Monday it deliberately
     * looks BACK at the Saturday just played — right for results and arguing,
     * wrong on exactly the two mornings the next site is usually announced.
     */
    $this->travelTo('2026-09-06 09:07');   // the Sunday after
    Http::fake(['*' => Http::response(gamedayFeedPayload('2026-09-12T09:00:00', 'AUSTIN, TX'))]);

    $this->artisan('cfb:gameday')->assertSuccessful();

    expect(GamedayWeek::sole()->saturday->toDateString())->toBe('2026-09-12');
});

it('stays off the air out of season', function () {
    $this->travelTo('2026-06-15 09:07');
    Http::preventStrayRequests();

    $this->artisan('cfb:gameday')
        ->expectsOutputToContain('Off-season')
        ->assertSuccessful();

    expect(GamedayWeek::count())->toBe(0);
    Http::assertNothingSent();
});
