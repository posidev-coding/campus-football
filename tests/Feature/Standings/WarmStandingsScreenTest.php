<?php

use App\Filament\Pages\SyncHealth;
use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Models\Game;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Services\Espn\Sync\ComputeStandings;
use App\Services\Espn\Sync\ReconcileStandings;
use App\Services\Espn\Sync\SyncStandings;
use App\Support\StandingsScreen;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
 * The standings screen read four cache entries on a bare visit that nothing
 * refreshed, so the first reader after each expiry paid for the queries —
 * and the row set's 900-second TTL made that reader a frequent one
 * (CFB-130). The cfb:sync steps that write standings now re-put them once
 * their write has landed, beside the position map #212 warms; the screen's
 * reads stay Remember read-throughs, so a cold cache is still correct.
 */
beforeEach(function () {
    Conference::factory()->create([
        'id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC', 'abbreviation' => 'sec',
    ]);
});

/** A season, with one finished game when it has been played and one scheduled when not. */
function screenWarmSeason(int $year, bool $played): void
{
    $season = Season::factory()->create([
        'year' => $year, 'type' => Season::REGULAR,
        'start_date' => "{$year}-08-22", 'end_date' => "{$year}-12-13",
    ]);

    $week = Week::create([
        'season_id' => $season->id, 'number' => 5, 'name' => 'Week 5',
        'start_date' => "{$year}-09-23", 'end_date' => "{$year}-09-29",
    ]);

    $played
        ? Game::factory()->finished()->create(['season_id' => $season->id, 'week_id' => $week->id])
        : Game::factory()->create(['season_id' => $season->id, 'week_id' => $week->id, 'completed' => false]);

    ConferenceSeason::create(['conference_id' => 8, 'season_year' => $year, 'classification' => 'FBS']);
}

/** One SEC team's ESPN standing for one season. */
function screenWarmStanding(int $id, int $year, int $wins, int $losses): void
{
    Team::query()->firstOrCreate(['id' => $id], Team::factory()->raw([
        'id' => $id, 'location' => "Screenwarm {$id}", 'display_name' => "Screenwarm {$id} Team", 'slug' => "screenwarm-{$id}",
    ]));

    Standing::create([
        'season_year' => $year, 'conference_id' => 8, 'team_id' => $id, 'source' => 'espn',
        'overall_wins' => $wins, 'overall_losses' => $losses, 'overall_ties' => 0,
        'conf_wins' => $wins, 'conf_losses' => $losses, 'conf_ties' => 0,
        'win_pct' => $wins + $losses > 0 ? round($wins / ($wins + $losses), 4) : null,
        'conf_win_pct' => $wins + $losses > 0 ? round($wins / ($wins + $losses), 4) : null,
    ]);
}

/** Rewrite one team's ESPN standing, the way a standings sync would. */
function screenWarmRestand(int $id, int $year, int $wins, int $losses): void
{
    Standing::query()->fromEspn()->where('season_year', $year)->where('team_id', $id)->update([
        'overall_wins' => $wins, 'overall_losses' => $losses, 'conf_wins' => $wins, 'conf_losses' => $losses,
        'win_pct' => round($wins / ($wins + $losses), 4), 'conf_win_pct' => round($wins / ($wins + $losses), 4),
    ]);
}

/** @return list<int> the team ids in the warmed FBS row set, in order */
function screenWarmOrder(int $year): array
{
    return array_map(fn (array $row) => $row['standing']['team_id'], Cache::get(StandingsScreen::rowsKey($year, 'fbs')) ?? []);
}

it('warms what a bare visit reads with exactly what the cold read builds', function () {
    screenWarmSeason(2025, played: true);
    screenWarmStanding(2633, 2025, 10, 2);
    screenWarmStanding(333, 2025, 9, 3);

    $this->artisan('cfb:sync', ['--only' => 'compute', '--year' => 'results'])->assertSuccessful();

    $keys = [
        StandingsScreen::publishedKey(2025),
        StandingsScreen::SEASONS_KEY,
        StandingsScreen::conferencesKey(2025, 'fbs'),
        StandingsScreen::rowsKey(2025, 'fbs'),
    ];

    $warmed = collect($keys)->mapWithKeys(fn (string $key) => [$key => Cache::get($key)])->all();

    expect($warmed[StandingsScreen::publishedKey(2025)])->toBeTrue()
        ->and($warmed[StandingsScreen::SEASONS_KEY])->toBe([2025])
        ->and($warmed[StandingsScreen::conferencesKey(2025, 'fbs')])->toBe([['id' => 8, 'label' => 'SEC']])
        ->and(screenWarmOrder(2025))->toBe([2633, 333]);

    // The cold read, through the screen itself, fills the same keys with
    // the same values — the warm is the read's own answer, not a sibling.
    Cache::flush();

    Livewire::test('standings')->assertSet('year', 2025)->assertSeeInOrder(['Screenwarm 2633', 'Screenwarm 333']);

    expect(collect($keys)->mapWithKeys(fn (string $key) => [$key => Cache::get($key)])->all())->toBe($warmed);
});

it('serves the screen from the warm, and re-warms after every standings step Sync Health can queue', function (string $step, string $service) {
    $command = collect(SyncHealth::runnableTasks())->keys()
        ->first(fn (string $task) => str_contains($task, "cfb:sync --only={$step} "));

    expect($command)->not->toBeNull("Sync Health no longer offers the {$step} step.");

    screenWarmSeason(2025, played: true);
    screenWarmStanding(2633, 2025, 9, 3);
    screenWarmStanding(333, 2025, 10, 2);

    StandingsScreen::warm(app(CfbCalendar::class));

    // A result written by something that is NOT a standings step stays off
    // the screen: the read is the warmed entry, not a fresh query.
    screenWarmRestand(2633, 2025, 11, 1);

    Livewire::test('standings')->assertSeeInOrder(['Screenwarm 333', 'Screenwarm 2633']);

    // Put it back, so the lead below can only come from the step's own
    // write — a warm taken BEFORE that write would still read 333 first.
    screenWarmRestand(2633, 2025, 9, 3);

    // The step's own write: Tennessee takes the lead.
    $this->mock($service, fn ($mock) => $mock->shouldReceive('handle')->once()->andReturnUsing(function (int $year) {
        screenWarmRestand(2633, $year, 11, 1);

        return 1;
    }));

    expect(Artisan::call($command))->toBe(0)
        ->and(screenWarmOrder(2025))->toBe([2633, 333]);

    Livewire::test('standings')->assertSeeInOrder(['Screenwarm 2633', 'Screenwarm 333']);
})->with([
    'standings' => ['standings', SyncStandings::class],
    'compute' => ['compute', ComputeStandings::class],
    'reconcile' => ['reconcile', ReconcileStandings::class],
]);

it('keeps the warm until the next nightly write, not for fifteen minutes', function () {
    screenWarmSeason(2025, played: true);
    screenWarmStanding(2633, 2025, 10, 2);

    $this->artisan('cfb:sync', ['--only' => 'reconcile', '--year' => 'results'])->assertSuccessful();

    $this->travel(20)->hours();

    expect(screenWarmOrder(2025))->toBe([2633])
        ->and(Cache::get(StandingsScreen::SEASONS_KEY))->toBe([2025]);

    // And it does age out, so a missed night falls back to the cold read.
    $this->travel(7)->hours();

    expect(Cache::has(StandingsScreen::rowsKey(2025, 'fbs')))->toBeFalse();
});

it('warms only once the write has landed, and never after a write that failed', function () {
    screenWarmSeason(2025, played: true);
    screenWarmStanding(2633, 2025, 9, 3);
    screenWarmStanding(333, 2025, 10, 2);

    StandingsScreen::warm(app(CfbCalendar::class));

    $this->mock(SyncStandings::class, fn ($mock) => $mock->shouldReceive('handle')->once()->andReturnUsing(function (int $year) {
        screenWarmRestand(2633, $year, 11, 1);

        throw new RuntimeException('ESPN went away mid-sync');
    }));

    expect(fn () => Artisan::call('cfb:sync', ['--only' => 'standings', '--year' => '2025']))
        ->toThrow(RuntimeException::class);

    expect(screenWarmOrder(2025))->toBe([333, 2633]);
});

it('warms nothing for a season with no standings rows', function () {
    screenWarmSeason(2025, played: true);

    $this->artisan('cfb:sync', ['--only' => 'reconcile', '--year' => 'results'])->assertSuccessful();

    expect(Cache::has(StandingsScreen::publishedKey(2025)))->toBeFalse()
        ->and(Cache::has(StandingsScreen::SEASONS_KEY))->toBeFalse()
        ->and(Cache::has(StandingsScreen::conferencesKey(2025, 'fbs')))->toBeFalse()
        ->and(Cache::has(StandingsScreen::rowsKey(2025, 'fbs')))->toBeFalse();
});

it('warms the season the screen opens on, which is not always the one the step wrote', function () {
    /*
     * September 2nd: 2025 is played, 2026 is scheduled, and ESPN has
     * published 2026 as 0-0 rows. The nightly writers run for the RESULTS
     * season (2025), but a bare visit opens on 2026 — so 2026 is the screen
     * worth warming, and warming the step's year would leave it cold.
     */
    config(['cfb.season' => 2024]);

    screenWarmSeason(2025, played: true);
    screenWarmSeason(2026, played: false);
    screenWarmStanding(2633, 2025, 10, 2);
    screenWarmStanding(2633, 2026, 0, 0);

    $this->artisan('cfb:sync', ['--only' => 'reconcile', '--year' => 'results'])->assertSuccessful();

    expect(Cache::get(StandingsScreen::publishedKey(2026)))->toBeTrue()
        ->and(screenWarmOrder(2026))->toBe([2633])
        ->and(Cache::has(StandingsScreen::rowsKey(2025, 'fbs')))->toBeFalse()
        ->and(Cache::has(StandingsScreen::rowsKey(2024, 'fbs')))->toBeFalse();

    Livewire::test('standings')->assertSet('year', 2026);
});

it('falls back to the results season until the scoreboard season publishes', function () {
    screenWarmSeason(2025, played: true);
    screenWarmSeason(2026, played: false);
    screenWarmStanding(2633, 2025, 10, 2);

    $this->artisan('cfb:sync', ['--only' => 'reconcile', '--year' => 'results'])->assertSuccessful();

    // "Not published yet" is never pinned: a request racing the sync that
    // lands 2026's first rows must still find them.
    expect(Cache::has(StandingsScreen::publishedKey(2026)))->toBeFalse()
        ->and(screenWarmOrder(2025))->toBe([2633])
        ->and(Cache::has(StandingsScreen::rowsKey(2026, 'fbs')))->toBeFalse();

    Livewire::test('standings')->assertSet('year', 2025);
});
