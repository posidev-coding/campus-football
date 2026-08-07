<?php

use App\Models\Conference;
use App\Models\Game;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Support\TeamGlance;
use Livewire\Livewire;

/*
 * The season league-wide team facts are read for.
 *
 * This is the offseason bug in new clothes — the app has now had it on
 * standings, on the team page's own default, and here on the home cards and
 * /teams. `resultsYear()` is the latest season with games PLAYED, so from
 * February to kickoff it is a year behind, and a screen defaulting through it
 * shows a finished record beside a conference lineup that may since have
 * realigned.
 *
 * The fixture puts "now" inside a season that is SCHEDULED but unplayed, which
 * is the only window where the two answers differ. A test written in November
 * passes either way and proves nothing. That needs real games, not just
 * seasons: `scoreboardYear()` asks whether the current season has a schedule
 * at all, and `resultsYear()` asks for a COMPLETED game — with neither present
 * both fall through to the config default and the test is measuring nothing.
 */
beforeEach(function () {
    $played = Season::factory()->create([
        'year' => 2025,
        'type' => Season::REGULAR,
        'start_date' => '2025-08-23',
        'end_date' => '2025-12-13',
    ]);

    $upcoming = Season::factory()->create([
        'year' => 2026,
        'type' => Season::REGULAR,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => now()->addMonths(4)->toDateString(),
    ]);

    Conference::factory()->create([
        'id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC', 'abbreviation' => 'sec',
    ]);

    Team::factory()->create([
        'id' => 2633, 'slug' => 'tennessee-volunteers', 'location' => 'Tennessee',
        'display_name' => 'Tennessee Volunteers', 'abbreviation' => 'TENN',
        'color' => 'ff8200', 'alt_color' => 'ffffff',
    ]);

    Team::factory()->create(['id' => 61, 'slug' => 'georgia-bulldogs', 'location' => 'Georgia']);

    // Makes 2025 the results year: a game actually played.
    Game::factory()->finished(31, 17)->create([
        'season_id' => $played->id, 'home_team_id' => 2633, 'away_team_id' => 61,
        'kickoff_at' => '2025-10-18 19:30:00',
    ]);

    // Makes 2026 the scoreboard year: a schedule exists, nothing played.
    Game::factory()->create([
        'season_id' => $upcoming->id, 'home_team_id' => 2633, 'away_team_id' => 61,
        'completed' => false, 'status' => 'pre',
        'kickoff_at' => now()->addMonth()->toDateTimeString(),
    ]);
});

function holdSeason(int $year, int $wins = 0, int $losses = 0): void
{
    TeamSeason::create([
        'team_id' => 2633, 'season_year' => $year, 'conference_id' => 8, 'classification' => 'FBS',
    ]);

    Standing::create([
        'team_id' => 2633, 'season_year' => $year, 'conference_id' => 8, 'source' => 'espn',
        'overall_wins' => $wins, 'overall_losses' => $losses,
        'conf_wins' => $wins, 'conf_losses' => $losses,
    ]);
}

it('reads the season we are heading into once we hold it', function () {
    holdSeason(2025, 10, 3);
    holdSeason(2026);

    TeamGlance::flush();

    expect(TeamGlance::year())->toBe(2026)
        ->and(TeamGlance::records()[2633]['overall'])->toBe('0-0');
});

it('falls back to the last played season when the new one is not synced yet', function () {
    /*
     * Not defensive — a season exists in the database months before it is
     * played, but not before its teams are SYNCED. Pointing the maps at a year
     * with no team_seasons rows empties records, conference names, standings
     * positions and the FBS picker all at once.
     */
    holdSeason(2025, 10, 3);

    TeamGlance::flush();

    expect(TeamGlance::year())->toBe(2025)
        ->and(TeamGlance::records()[2633]['overall'])->toBe('10-3');
});

it('does not pin "not held yet" while the season is still syncing', function () {
    /*
     * The Remember::filled rule, and why this is not Cache::remember: a lookup
     * that ran mid-backfill would otherwise cache "2026 is not held" for the
     * full TTL and keep every screen a year back until it expired. Called
     * TWICE, because a single-call test always passes.
     */
    holdSeason(2025, 10, 3);

    TeamGlance::flush();
    expect(TeamGlance::year())->toBe(2025);

    holdSeason(2026);

    TeamGlance::flush();
    expect(TeamGlance::year())->toBe(2026);
});

it('opens the Teams screen on that same season', function () {
    // Shared rather than re-derived, so the two cannot name different seasons
    // for one team. It listed last season's conference membership before —
    // the one thing this screen exists to get right.
    holdSeason(2025, 10, 3);
    holdSeason(2026);

    TeamGlance::flush();

    Livewire::test('teams')->assertSet('year', 2026);
});

it('still lets a reader ask for an older season', function () {
    holdSeason(2025, 10, 3);
    holdSeason(2026);

    TeamGlance::flush();

    Livewire::withUrlParams(['year' => 2025])
        ->test('teams')
        ->assertSet('year', 2025);
});
