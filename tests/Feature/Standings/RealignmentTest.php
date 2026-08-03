<?php

use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Services\Espn\Sync\SyncTeams;
use Illuminate\Support\Facades\Http;

/*
 * The root cause of three versions of broken standings.
 *
 * ESPN scopes conference membership to a season. v3 stored it as a single
 * `teams.conference_id` scalar, which cannot represent a team that moved — and
 * conference realignment between 2021 and 2025 moved a great many of them.
 *
 * Verified against the live API during planning: Oregon's season-scoped team
 * resource points at group 54 (Pac-12) for 2021 and group 5 (Big Ten) for 2025.
 */

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);

    Conference::factory()->create(['id' => 54, 'name' => 'Pac-12 Conference']);
    Conference::factory()->create(['id' => 5, 'name' => 'Big Ten Conference']);

    foreach ([[54, 2021], [5, 2025]] as [$conferenceId, $year]) {
        ConferenceSeason::create([
            'conference_id' => $conferenceId,
            'season_year' => $year,
            'classification' => 'FBS',
        ]);
    }
});

function fakeOregonIn(int $groupId, int $year): void
{
    $base = 'https://sports.core.api.espn.com/v2/sports/football/leagues/college-football';

    Http::fake([
        "*seasons/{$year}/teams?*" => Http::response([
            'count' => 1,
            'pageCount' => 1,
            'items' => [['$ref' => "{$base}/seasons/{$year}/teams/2483?lang=en"]],
        ]),
        "*seasons/{$year}/teams/2483*" => Http::response([
            'id' => '2483',
            'slug' => 'oregon-ducks',
            'location' => 'Oregon',
            'name' => 'Ducks',
            'displayName' => 'Oregon Ducks',
            'color' => '154733',
            'alternateColor' => 'fee123',
            'logos' => [
                ['href' => 'https://a.espncdn.com/i/teamlogos/ncaa/500/2483.png'],
                ['href' => 'https://a.espncdn.com/i/teamlogos/ncaa/500-dark/2483.png'],
            ],
            // The season-scoped conference pointer — the whole fix.
            'groups' => ['$ref' => "{$base}/seasons/{$year}/types/3/groups/{$groupId}?lang=en"],
        ]),
    ]);
}

it('records Oregon in the Pac-12 for 2021', function () {
    fakeOregonIn(54, 2021);

    app(SyncTeams::class)->handle(2021);

    expect(TeamSeason::where('team_id', 2483)->where('season_year', 2021)->sole()->conference_id)
        ->toBe(54);
});

it('records Oregon in the Big Ten for 2025', function () {
    fakeOregonIn(5, 2025);

    app(SyncTeams::class)->handle(2025);

    expect(TeamSeason::where('team_id', 2483)->where('season_year', 2025)->sole()->conference_id)
        ->toBe(5);
});

it('keeps both memberships simultaneously rather than overwriting', function () {
    // The assertion v3's schema could not satisfy at all: one team, two
    // seasons, two different conferences, both true at once.
    fakeOregonIn(54, 2021);
    app(SyncTeams::class)->handle(2021);

    fakeOregonIn(5, 2025);
    app(SyncTeams::class)->handle(2025);

    $memberships = TeamSeason::where('team_id', 2483)
        ->orderBy('season_year')
        ->pluck('conference_id', 'season_year');

    expect($memberships[2021])->toBe(54)
        ->and($memberships[2025])->toBe(5)
        ->and(Team::whereKey(2483)->count())->toBe(1);
});

it('re-syncing a season updates in place instead of duplicating', function () {
    fakeOregonIn(5, 2025);

    app(SyncTeams::class)->handle(2025);
    app(SyncTeams::class)->handle(2025);

    expect(TeamSeason::where('team_id', 2483)->where('season_year', 2025)->count())->toBe(1);
});

it('captures the dark logo variant, since the app is dark-mode-first', function () {
    fakeOregonIn(5, 2025);

    app(SyncTeams::class)->handle(2025);

    $team = Team::whereKey(2483)->sole();

    expect($team->logo)->toContain('/500/2483.png')
        ->and($team->logo_dark)->toContain('/500-dark/2483.png')
        ->and($team->accentColor())->toBe('#154733');
});

it('inherits classification from the conference tree', function () {
    fakeOregonIn(5, 2025);

    app(SyncTeams::class)->handle(2025);

    expect(TeamSeason::where('team_id', 2483)->sole()->classification)->toBe('FBS');
});
