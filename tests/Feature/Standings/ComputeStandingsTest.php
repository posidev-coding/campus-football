<?php

use App\Enums\StandingSource;
use App\Models\Conference;
use App\Models\Game;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Services\Espn\Sync\ComputeStandings;
use App\Services\Espn\Sync\ReconcileStandings;

beforeEach(function () {
    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);

    $this->sec = Conference::factory()->create(['id' => 8, 'name' => 'SEC']);
    $this->b1g = Conference::factory()->create(['id' => 5, 'name' => 'Big Ten']);

    $this->georgia = Team::factory()->create(['id' => 61, 'display_name' => 'Georgia']);
    $this->alabama = Team::factory()->create(['id' => 333, 'display_name' => 'Alabama']);
    $this->oregon = Team::factory()->create(['id' => 2483, 'display_name' => 'Oregon']);

    TeamSeason::create(['team_id' => 61, 'season_year' => 2025, 'conference_id' => 8]);
    TeamSeason::create(['team_id' => 333, 'season_year' => 2025, 'conference_id' => 8]);
    TeamSeason::create(['team_id' => 2483, 'season_year' => 2025, 'conference_id' => 5]);
});

function playedGame(int $homeId, int $awayId, int $homeScore, int $awayScore, int $seasonId): Game
{
    return Game::factory()->finished($homeScore, $awayScore)->create([
        'season_id' => $seasonId,
        'home_team_id' => $homeId,
        'away_team_id' => $awayId,
    ]);
}

it('counts a game between two conference mates as a conference game', function () {
    playedGame(61, 333, 31, 17, $this->season->id);

    app(ComputeStandings::class)->handle(2025);

    $georgia = Standing::computed()->where('team_id', 61)->sole();
    $alabama = Standing::computed()->where('team_id', 333)->sole();

    expect($georgia->conf_wins)->toBe(1)
        ->and($georgia->overall_wins)->toBe(1)
        ->and($alabama->conf_losses)->toBe(1)
        ->and($alabama->overall_losses)->toBe(1);
});

it('does not count a cross-conference game toward the conference record', function () {
    // Georgia (SEC) vs Oregon (Big Ten) counts overall but not in-conference.
    playedGame(61, 2483, 24, 21, $this->season->id);

    app(ComputeStandings::class)->handle(2025);

    $georgia = Standing::computed()->where('team_id', 61)->sole();

    expect($georgia->overall_wins)->toBe(1)
        ->and($georgia->conf_wins)->toBe(0);
});

it('counts only regular-season games, not the playoff', function () {
    // ESPN's types/2 standings stop at the end of the regular season. Counting
    // CFP results here made Indiana read 16-0 against ESPN's 13-0 and flagged
    // five playoff teams as diverged.
    $postseason = Season::factory()->create(['year' => 2025, 'type' => Season::POSTSEASON]);

    playedGame(61, 333, 31, 17, $this->season->id);
    playedGame(61, 2483, 40, 10, $postseason->id);

    app(ComputeStandings::class)->handle(2025);

    expect(Standing::computed()->where('team_id', 61)->sole()->overall_wins)->toBe(1);
});

it('ignores games that have not finished', function () {
    Game::factory()->create([
        'season_id' => $this->season->id,
        'home_team_id' => 61,
        'away_team_id' => 333,
        'completed' => false,
    ]);

    app(ComputeStandings::class)->handle(2025);

    expect(Standing::computed()->where('team_id', 61)->exists())->toBeFalse();
});

it('records a tie for both sides', function () {
    playedGame(61, 333, 21, 21, $this->season->id);

    app(ComputeStandings::class)->handle(2025);

    expect(Standing::computed()->where('team_id', 61)->sole()->conf_ties)->toBe(1)
        ->and(Standing::computed()->where('team_id', 333)->sole()->conf_ties)->toBe(1);
});

it('accumulates points for and against', function () {
    playedGame(61, 333, 31, 17, $this->season->id);
    playedGame(2483, 61, 10, 27, $this->season->id);

    app(ComputeStandings::class)->handle(2025);

    $georgia = Standing::computed()->where('team_id', 61)->sole();

    expect($georgia->points_for)->toBe(58)
        ->and($georgia->points_against)->toBe(27)
        ->and($georgia->point_differential)->toBe(31);
});

it('flags divergence when the feed disagrees with our own count', function () {
    playedGame(61, 333, 31, 17, $this->season->id);
    app(ComputeStandings::class)->handle(2025);

    // ESPN claims a record that our games cannot support.
    Standing::create([
        'season_year' => 2025,
        'conference_id' => 8,
        'team_id' => 61,
        'source' => StandingSource::Espn,
        'overall_wins' => 9,
        'overall_losses' => 0,
        'conf_wins' => 6,
        'conf_losses' => 0,
    ]);

    $flagged = app(ReconcileStandings::class)->handle(2025);

    $espn = Standing::fromEspn()->where('team_id', 61)->sole();

    expect($flagged)->toBe(1)
        ->and($espn->diverged_at)->not->toBeNull()
        ->and($espn->divergence)->toHaveKey('conf_wins')
        ->and($espn->divergence['conf_wins'])->toBe(['espn' => 6, 'computed' => 1]);
});

it('does not flag a difference within tolerance', function () {
    // One game of drift is normal — an FCS opponent, or a game not yet ingested.
    playedGame(61, 333, 31, 17, $this->season->id);
    app(ComputeStandings::class)->handle(2025);

    Standing::create([
        'season_year' => 2025,
        'conference_id' => 8,
        'team_id' => 61,
        'source' => StandingSource::Espn,
        'overall_wins' => 2,
        'conf_wins' => 2,
    ]);

    expect(app(ReconcileStandings::class)->handle(2025))->toBe(0)
        ->and(Standing::fromEspn()->where('team_id', 61)->sole()->diverged_at)->toBeNull();
});

it('clears the divergence flag once the sources agree again', function () {
    playedGame(61, 333, 31, 17, $this->season->id);
    app(ComputeStandings::class)->handle(2025);

    $espn = Standing::create([
        'season_year' => 2025,
        'conference_id' => 8,
        'team_id' => 61,
        'source' => StandingSource::Espn,
        'conf_wins' => 9,
        'diverged_at' => now()->subDay(),
        'divergence' => ['conf_wins' => ['espn' => 9, 'computed' => 1]],
    ]);

    $espn->forceFill(['conf_wins' => 1])->save();

    app(ReconcileStandings::class)->handle(2025);

    expect($espn->fresh()->diverged_at)->toBeNull();
});

it('keeps the two sources as separate rows and never merges them', function () {
    playedGame(61, 333, 31, 17, $this->season->id);
    app(ComputeStandings::class)->handle(2025);

    Standing::create([
        'season_year' => 2025,
        'conference_id' => 8,
        'team_id' => 61,
        'source' => StandingSource::Espn,
        'conf_wins' => 6,
    ]);

    expect(Standing::where('team_id', 61)->count())->toBe(2)
        ->and(Standing::computed()->where('team_id', 61)->sole()->conf_wins)->toBe(1)
        ->and(Standing::fromEspn()->where('team_id', 61)->sole()->conf_wins)->toBe(6);
});
