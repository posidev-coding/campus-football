<?php

use App\Jobs\FetchCoach;
use App\Models\Coach;
use App\Models\CoachTeamSeason;
use App\Models\Team;
use App\Services\Espn\Sync\SyncCoaches;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);

    Team::factory()->create(['id' => 201, 'display_name' => 'Oklahoma Sooners']);
    Team::factory()->create(['id' => 30, 'display_name' => 'USC Trojans']);
});

/**
 * The Lincoln Riley shape: two tenures at two schools, a full-name state,
 * and a headshot that does not resolve.
 */
function fakeCoachEndpoints(array $overrides = []): void
{
    Http::fake(array_merge([
        '*/coaches/145698/record/0*' => Http::response([
            'name' => 'Total', 'summary' => '87-30-0',
            'stats' => [
                ['name' => 'wins', 'value' => 87.0],
                ['name' => 'losses', 'value' => 30.0],
                ['name' => 'ties', 'value' => 0.0],
            ],
        ]),
        '*/seasons/2021/types/2/coaches/145698/record*' => Http::response(['summary' => '10-2-0']),
        '*/seasons/2025/types/2/coaches/145698/record*' => Http::response(['summary' => '9-3-0']),
        '*/seasons/2021/coaches/145698*' => Http::response([
            'team' => ['$ref' => 'http://sports.core.api.espn.com/v2/sports/football/leagues/college-football/seasons/2021/teams/201?lang=en'],
            'experience' => 5,
        ]),
        '*/seasons/2025/coaches/145698*' => Http::response([
            'team' => ['$ref' => 'http://sports.core.api.espn.com/v2/sports/football/leagues/college-football/seasons/2025/teams/30?lang=en'],
            'experience' => 9,
        ]),
        '*/coaches/145698*' => Http::response([
            'id' => '145698',
            'firstName' => 'Lincoln', 'lastName' => 'Riley',
            'dateOfBirth' => '1983-09-05T07:00Z',
            'birthPlace' => ['city' => 'Muleshoe', 'state' => 'Texas', 'country' => 'USA'],
            'experience' => 9,
            'coachSeasons' => [
                ['$ref' => 'http://sports.core.api.espn.com/v2/sports/football/leagues/college-football/seasons/2021/coaches/145698?lang=en'],
                ['$ref' => 'http://sports.core.api.espn.com/v2/sports/football/leagues/college-football/seasons/2025/coaches/145698?lang=en'],
            ],
        ]),
        'https://a.espncdn.com/*' => Http::response('', 404),
    ], $overrides));
}

it('captures a move between schools as two tenure rows with records', function () {
    fakeCoachEndpoints();

    expect(app(SyncCoaches::class)->handle(145698))->toBeTrue();

    $tenures = CoachTeamSeason::where('coach_id', 145698)->orderBy('season_year')->get();

    expect($tenures)->toHaveCount(2)
        ->and($tenures[0]->team_id)->toBe(201)
        ->and($tenures[0]->record())->toBe('10-2')
        ->and($tenures[1]->team_id)->toBe(30)
        ->and($tenures[1]->record())->toBe('9-3');
});

it('normalizes the full state name to the two-letter code athletes use', function () {
    fakeCoachEndpoints();

    app(SyncCoaches::class)->handle(145698);

    $coach = Coach::find(145698);

    expect($coach->birth_state)->toBe('TX')
        ->and($coach->hometown())->toBe('Muleshoe, TX')
        ->and($coach->careerRecord())->toBe('87-30');
});

it('leaves the headshot null when the CDN has none', function () {
    fakeCoachEndpoints();

    app(SyncCoaches::class)->handle(145698);

    expect(Coach::find(145698)->headshot_url)->toBeNull();
});

it('stores the headshot only on a CDN hit', function () {
    fakeCoachEndpoints([
        'https://a.espncdn.com/*' => Http::response(''),
    ]);

    app(SyncCoaches::class)->handle(145698);

    expect(Coach::find(145698)->headshot_url)
        ->toBe('https://a.espncdn.com/i/headshots/college-football/players/full/145698.png');
});

it('makes 2 + 2N API requests and one CDN probe for an N-season coach', function () {
    fakeCoachEndpoints();

    app(SyncCoaches::class)->handle(145698);

    // 1 coach + 1 career record + 2 seasons × (document + record) + 1 HEAD.
    Http::assertSentCount(7);
});

it('touches only the latest season when refreshing', function () {
    fakeCoachEndpoints();

    app(SyncCoaches::class)->handle(145698, currentSeasonOnly: true);

    // Coach + career + ONE season pair + CDN probe; 2021 is never re-asked.
    Http::assertSentCount(5);

    expect(CoachTeamSeason::where('coach_id', 145698)->pluck('season_year')->all())->toBe([2025]);
});

it('writes nothing when ESPN has no document for the coach', function () {
    Http::fake(['*' => Http::response('', 404)]);

    expect(app(SyncCoaches::class)->handle(999))->toBeFalse()
        ->and(Coach::whereKey(999)->exists())->toBeFalse();
});

it('skips a season whose team we do not know rather than inventing one', function () {
    fakeCoachEndpoints([
        '*/seasons/2021/coaches/145698*' => Http::response([
            'team' => ['$ref' => 'http://sports.core.api.espn.com/v2/sports/football/leagues/college-football/seasons/2021/teams/424242?lang=en'],
        ]),
    ]);

    app(SyncCoaches::class)->handle(145698);

    expect(CoachTeamSeason::where('coach_id', 145698)->pluck('team_id')->all())->toBe([30]);
});

it('fans out one job per coach through cfb:coaches', function () {
    Bus::fake();

    Coach::create(['id' => 145698, 'display_name' => 'Lincoln Riley']);
    Coach::create(['id' => 3960423, 'display_name' => 'Kirby Smart']);

    $this->artisan('cfb:coaches')->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2
        && $batch->jobs->every(fn ($job) => $job instanceof FetchCoach));
});

it('resumes with --missing rather than restarting', function () {
    Bus::fake();

    Coach::create(['id' => 145698, 'display_name' => 'Lincoln Riley', 'career_wins' => 87, 'career_losses' => 30]);
    Coach::create(['id' => 3960423, 'display_name' => 'Kirby Smart']);

    $this->artisan('cfb:coaches', ['--missing' => true])->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1
        && $batch->jobs->first()->coachId === 3960423);
});
