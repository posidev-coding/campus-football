<?php

use App\Models\Conference;
use App\Models\Game;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\Week;
use App\Support\TeamGlance;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

/*
 * The conference-position map Home, the team page and search all read was a
 * 900-second cache that nothing refreshed, so every fifteen minutes whoever
 * opened Home first rebuilt the whole league's standings on the request path
 * (2424ms, measured). The nightly `positions` step now re-puts it straight
 * after the standings steps write, and the read stays a plain remember so a
 * cold cache is still correct.
 */
beforeEach(function () {
    Conference::factory()->create([
        'id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC', 'abbreviation' => 'sec',
    ]);
});

/** A team with one standing in its own conference for one season. */
function warmedTeam(int $id, int $year, int $conference, int $wins, int $losses): void
{
    Team::query()->firstOrCreate(['id' => $id], Team::factory()->raw([
        'id' => $id, 'display_name' => "Team {$id}", 'slug' => "team-{$id}",
    ]));

    TeamSeason::create(['team_id' => $id, 'season_year' => $year, 'conference_id' => $conference, 'classification' => 'FBS']);

    Standing::create([
        'season_year' => $year, 'conference_id' => $conference, 'team_id' => $id, 'source' => 'espn',
        'overall_wins' => $wins, 'overall_losses' => $losses, 'overall_ties' => 0,
        'conf_wins' => $wins, 'conf_losses' => $losses, 'conf_ties' => 0,
        'win_pct' => $wins + $losses > 0 ? round($wins / ($wins + $losses), 4) : null,
        'conf_win_pct' => $wins + $losses > 0 ? round($wins / ($wins + $losses), 4) : null,
    ]);
}

/** A season with one finished game, so the calendar counts it as played. */
function playedSeason(int $year): void
{
    $season = Season::factory()->create([
        'year' => $year, 'type' => Season::REGULAR,
        'start_date' => "{$year}-08-23", 'end_date' => "{$year}-12-13",
    ]);

    $week = Week::create([
        'season_id' => $season->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => "{$year}-09-23", 'end_date' => "{$year}-09-29",
    ]);

    Game::factory()->finished()->create(['season_id' => $season->id, 'week_id' => $week->id]);
}

it('warms the cache with exactly the map the uncached read builds', function () {
    warmedTeam(2633, 2025, 8, 10, 2);   // Tennessee
    warmedTeam(333, 2025, 8, 9, 3);     // Alabama
    warmedTeam(2, 2025, 8, 5, 7);       // Auburn

    $uncached = TeamGlance::standingPositions(2025);

    Cache::flush();
    TeamGlance::flush();

    $this->artisan('cfb:sync', ['--only' => 'positions', '--year' => '2025'])->assertSuccessful();

    expect($uncached)->toBe([2633 => 1, 333 => 2, 2 => 3])
        ->and(Cache::get('glance:positions:v3:2025'))->toBe($uncached);
});

it('keeps the warmed map alive until the next nightly warm, not for fifteen minutes', function () {
    /*
     * The whole point of the step. A warm put with the read's own 900 seconds
     * expires at 05:02 and the 08:00 visitor rebuilds it cold exactly as
     * before — the cache would be warm for nobody.
     */
    warmedTeam(2633, 2025, 8, 10, 2);
    warmedTeam(333, 2025, 8, 9, 3);

    $this->artisan('cfb:sync', ['--only' => 'positions', '--year' => '2025'])->assertSuccessful();

    $this->travel(20)->hours();

    expect(Cache::get('glance:positions:v3:2025'))->toBe([2633 => 1, 333 => 2]);

    // And it does age out, so a missed night falls back to the cold read
    // rather than serving a week-old table.
    $this->travel(7)->hours();

    expect(Cache::has('glance:positions:v3:2025'))->toBeFalse();
});

it('warms the season the calendar names, never a literal or the config year', function () {
    /*
     * 2024 and 2025 are both played and both hold standings; 2026 is on the
     * calendar with no games yet, which is September 2nd in the real world.
     * Config names 2024, so anything reading `cfb.season` instead of the
     * calendar warms the wrong year and leaves the screens' one cold.
     */
    config(['cfb.season' => 2024]);

    playedSeason(2024);
    playedSeason(2025);
    Season::factory()->create([
        'year' => 2026, 'type' => Season::REGULAR,
        'start_date' => '2026-08-22', 'end_date' => '2026-12-13',
    ]);

    foreach ([2024, 2025] as $year) {
        warmedTeam(2633, $year, 8, 10, 2);
        warmedTeam(333, $year, 8, 9, 3);
    }

    // The schedule names the season by token, resolved when it runs.
    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn (Event $event) => $event->command ?? '')
        ->first(fn (string $command) => str_contains($command, '--only=positions'));

    expect($scheduled)->toContain('--year=results');

    $this->artisan('cfb:sync', ['--only' => 'positions', '--year' => 'results'])->assertSuccessful();

    expect(Cache::get('glance:positions:v3:2025'))->toBe([2633 => 1, 333 => 2])
        ->and(Cache::has('glance:positions:v3:2024'))->toBeFalse()
        ->and(Cache::has('glance:positions:v3:2026'))->toBeFalse();
});

it('warms an unplayed conference to no positions, not to first place', function () {
    /*
     * Every SEC team 0-0 while the Sun Belt is three games in. The warm must
     * write the read's own answer — nothing for the SEC — and never a "1st"
     * pulled from insertion order. An empty entry is a real answer, so the
     * key is written all the same.
     */
    Conference::factory()->create(['id' => 37, 'name' => 'Sun Belt Conference', 'short_name' => 'Sun Belt']);

    warmedTeam(2633, 2025, 8, 0, 0);
    warmedTeam(333, 2025, 8, 0, 0);
    warmedTeam(2026, 2025, 37, 3, 0);
    warmedTeam(309, 2025, 37, 1, 2);

    $this->artisan('cfb:sync', ['--only' => 'positions', '--year' => '2025'])->assertSuccessful();

    expect(Cache::get('glance:positions:v3:2025'))->toBe([2026 => 1, 309 => 2]);
});

it('warms a season nobody has played to an empty map, and the read serves it', function () {
    warmedTeam(2633, 2025, 8, 0, 0);
    warmedTeam(333, 2025, 8, 0, 0);

    $this->artisan('cfb:sync', ['--only' => 'positions', '--year' => '2025'])->assertSuccessful();

    expect(Cache::has('glance:positions:v3:2025'))->toBeTrue()
        ->and(Cache::get('glance:positions:v3:2025'))->toBe([]);

    // Served from the warm, not rebuilt: a new result landing after the warm
    // is invisible until the next one.
    Standing::query()->where('team_id', 2633)->update(['overall_wins' => 1, 'conf_wins' => 1, 'win_pct' => 1, 'conf_win_pct' => 1]);
    TeamGlance::flush();

    expect(TeamGlance::standingPositions(2025))->toBe([]);
});
