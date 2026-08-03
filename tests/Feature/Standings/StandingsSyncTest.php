<?php

use App\Enums\StandingSource;
use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Models\Standing;
use App\Models\Team;
use App\Services\Espn\Sync\SyncStandings;
use Illuminate\Support\Facades\Http;

/*
 * The standings sync is the acceptance test for the whole rebuild. These cases
 * are written against the exact failure modes of v3, not against happy paths.
 */

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);

    $this->conference = Conference::factory()->create(['id' => 8, 'name' => 'SEC']);
    ConferenceSeason::create([
        'conference_id' => 8,
        'season_year' => 2025,
        'classification' => 'FBS',
    ]);
    $this->team = Team::factory()->create(['id' => 61, 'display_name' => 'Georgia Bulldogs']);
});

/**
 * Shaped exactly like the live payload verified during planning: records
 * addressed by `type`, stats as a name/value list.
 */
function standingsPayload(array $overallStats, ?array $confStats = null): array
{
    $records = [[
        'name' => 'overall',
        'type' => 'total',
        'displayValue' => '12-1',
        'stats' => collect($overallStats)->map(fn ($v, $k) => ['name' => $k, 'value' => $v])->values()->all(),
    ]];

    if ($confStats !== null) {
        $records[] = [
            'name' => 'vs. Conf.',
            'type' => 'vsconf',
            'displayValue' => '7-1',
            'stats' => collect($confStats)->map(fn ($v, $k) => ['name' => $k, 'value' => $v])->values()->all(),
        ];
    }

    return [
        'id' => '0',
        'name' => 'overall',
        'standings' => [[
            'team' => ['$ref' => 'https://sports.core.api.espn.com/v2/sports/football/leagues/college-football/seasons/2025/teams/61?lang=en'],
            'records' => $records,
        ]],
    ];
}

it('reads the conference record from the vsconf entry', function () {
    Http::fake(['*' => Http::response(standingsPayload(
        ['wins' => 12, 'losses' => 1, 'ties' => 0, 'pointsFor' => 415, 'pointsAgainst' => 207],
        ['wins' => 7, 'losses' => 1, 'ties' => 0],
    ))]);

    app(SyncStandings::class)->syncConference(2025, 8);

    $standing = Standing::fromEspn()->sole();

    expect($standing->team_id)->toBe(61)
        ->and($standing->conf_wins)->toBe(7)
        ->and($standing->conf_losses)->toBe(1)
        ->and($standing->overall_wins)->toBe(12)
        ->and($standing->overall_losses)->toBe(1)
        ->and($standing->conferenceRecord())->toBe('7-1');
});

it('reads records by type rather than array position', function () {
    // The same data with the records array reversed must produce an identical
    // row. v3 indexed stats positionally, which is what commit dde53b3 fixed
    // once and would have broken again on any ESPN reordering.
    $payload = standingsPayload(
        ['wins' => 12, 'losses' => 1],
        ['wins' => 7, 'losses' => 1],
    );
    $payload['standings'][0]['records'] = array_reverse($payload['standings'][0]['records']);

    Http::fake(['*' => Http::response($payload)]);

    app(SyncStandings::class)->syncConference(2025, 8);

    $standing = Standing::fromEspn()->sole();

    expect($standing->conf_wins)->toBe(7)
        ->and($standing->overall_wins)->toBe(12);
});

it('never overwrites a good record with zeros when the feed returns nothing', function () {
    // This is the v3 data-destroying bug, reproduced exactly: a team with a
    // real record, followed by a sync where the lookup misses.
    Standing::create([
        'season_year' => 2025,
        'conference_id' => 8,
        'team_id' => 61,
        'source' => StandingSource::Espn,
        'overall_wins' => 9,
        'overall_losses' => 1,
        'conf_wins' => 6,
        'conf_losses' => 1,
    ]);

    Http::fake(['*' => Http::response('', 404)]);

    app(SyncStandings::class)->syncConference(2025, 8);

    $standing = Standing::fromEspn()->sole();

    expect($standing->overall_wins)->toBe(9)
        ->and($standing->conf_wins)->toBe(6);
});

it('leaves existing rows untouched when the payload is malformed', function () {
    Standing::create([
        'season_year' => 2025,
        'conference_id' => 8,
        'team_id' => 61,
        'source' => StandingSource::Espn,
        'overall_wins' => 9,
        'conf_wins' => 6,
    ]);

    // Structurally valid JSON, but no usable records.
    Http::fake(['*' => Http::response([
        'standings' => [[
            'team' => ['$ref' => 'https://espn/seasons/2025/teams/61'],
            'records' => [['name' => 'nonsense', 'type' => 'unknown', 'stats' => []]],
        ]],
    ])]);

    app(SyncStandings::class)->syncConference(2025, 8);

    expect(Standing::fromEspn()->sole()->overall_wins)->toBe(9);
});

it('omits absent stats rather than defaulting them to zero', function () {
    // Only wins/losses present — pointsFor and playoff seed are missing.
    Http::fake(['*' => Http::response(standingsPayload(
        ['wins' => 12, 'losses' => 1],
        ['wins' => 7, 'losses' => 1],
    ))]);

    app(SyncStandings::class)->syncConference(2025, 8);

    $standing = Standing::fromEspn()->sole();

    // The column default is 0, but nothing was written for it, and the seed
    // stays null rather than being invented.
    expect($standing->playoff_seed)->toBeNull();
});

it('parses a signed streak into W/L notation', function () {
    Http::fake(['*' => Http::response(standingsPayload(
        ['wins' => 12, 'losses' => 1, 'streak' => 5],
        ['wins' => 7, 'losses' => 1],
    ))]);

    app(SyncStandings::class)->syncConference(2025, 8);

    expect(Standing::fromEspn()->sole()->streak)->toBe('W5');
});

it('handles a tie record without garbling it', function () {
    Http::fake(['*' => Http::response(standingsPayload(
        ['wins' => 10, 'losses' => 2, 'ties' => 1],
        ['wins' => 6, 'losses' => 1, 'ties' => 1],
    ))]);

    app(SyncStandings::class)->syncConference(2025, 8);

    $standing = Standing::fromEspn()->sole();

    // v3's explode('-') produced nonsense on "10-2-1".
    expect($standing->overallRecord())->toBe('10-2-1')
        ->and($standing->conferenceRecord())->toBe('6-1-1');
});
