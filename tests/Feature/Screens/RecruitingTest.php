<?php

use App\Models\Position;
use App\Models\Recruit;
use App\Models\Team;
use App\Services\Espn\Sync\SyncRecruiting;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);

    $this->lsu = Team::factory()->create([
        'id' => 99,
        'slug' => 'lsu-tigers',
        'display_name' => 'LSU Tigers',
        'abbreviation' => 'LSU',
    ]);

    Position::create(['id' => 7, 'name' => 'Defensive Tackle', 'abbreviation' => 'DT']);
});

function recruitPayload(int $id, string $name, int $rank, int $grade, ?int $teamId = 99): array
{
    return [
        'recruitingClass' => 2026,
        'status' => ['id' => 3, 'description' => 'Signed'],
        'grade' => $grade,
        'athlete' => [
            'id' => (string) $id,
            'alternateId' => '5158949',
            'firstName' => explode(' ', $name)[0],
            'lastName' => explode(' ', $name)[1] ?? '',
            'fullName' => $name,
            'height' => 77.0,
            'weight' => 285.0,
            'highSchool' => [
                'properName' => 'University Laboratory School',
                'address' => ['city' => 'Baton Rouge', 'state' => 'Louisiana'],
            ],
            'hometown' => ['city' => 'Baton Rouge', 'state' => 'Louisiana', 'stateAbbreviation' => 'LA'],
            'position' => ['id' => '7', 'abbreviation' => 'DT'],
        ],
        'attributes' => [
            ['name' => 'rank', 'value' => $rank],
            ['name' => 'positionRank', 'value' => 1],
            ['name' => 'stateRank', 'value' => 1],
        ],
        'schools' => $teamId === null ? [] : [[
            'status' => ['id' => 3, 'description' => 'Signed'],
            'team' => ['$ref' => "https://espn/seasons/2026/teams/{$teamId}"],
        ]],
    ];
}

function fakeRecruitingClass(array $recruits): void
{
    $refs = [];
    $stubs = ['*recruiting*athletes*' => null];

    foreach ($recruits as $i => $r) {
        $refs[] = ['$ref' => "https://espn/recruits/{$i}"];
    }

    Http::fake(array_merge([
        '*recruiting*' => Http::response(['count' => count($recruits), 'pageCount' => 1, 'items' => $refs]),
    ], collect($recruits)->mapWithKeys(fn ($r, $i) => ["https://espn/recruits/{$i}" => Http::response($r)])->all()));
}

it('stores a prospect with name, school, hometown and ranks', function () {
    fakeRecruitingClass([recruitPayload(256474, 'Lamar Brown', 1, 94)]);

    expect(app(SyncRecruiting::class)->handle(2026))->toBe(1);

    $recruit = Recruit::sole();

    expect($recruit->display_name)->toBe('Lamar Brown')
        ->and($recruit->grade)->toBe(94)
        ->and($recruit->national_rank)->toBe(1)
        ->and($recruit->high_school)->toBe('University Laboratory School')
        ->and($recruit->hometown())->toBe('Baton Rouge, LA')
        ->and($recruit->committed_team_id)->toBe(99)
        ->and($recruit->status)->toBe('Signed');
});

it('does not link an athlete we do not hold', function () {
    // alternateId is the id the prospect carries once on a college roster;
    // linking it blindly would break the foreign key.
    fakeRecruitingClass([recruitPayload(256474, 'Lamar Brown', 1, 94)]);

    app(SyncRecruiting::class)->handle(2026);

    expect(Recruit::sole()->athlete_id)->toBeNull();
});

it('leaves the committed team null when the school is not one we carry', function () {
    fakeRecruitingClass([recruitPayload(256474, 'Lamar Brown', 1, 94, teamId: 88888)]);

    app(SyncRecruiting::class)->handle(2026);

    expect(Recruit::sole()->committed_team_id)->toBeNull();
});

it('stops at the cap, taking the top of the class', function () {
    fakeRecruitingClass([
        recruitPayload(1, 'First Prospect', 1, 95),
        recruitPayload(2, 'Second Prospect', 2, 94),
        recruitPayload(3, 'Third Prospect', 3, 93),
    ]);

    // A full class is ~5,200 requests, so the cap is what keeps this sane.
    expect(app(SyncRecruiting::class)->handle(2026, limit: 2))->toBe(2)
        ->and(Recruit::count())->toBe(2);
});

it('renders the prospect list', function () {
    Recruit::create([
        'espn_id' => 256474, 'recruiting_class' => 2026, 'display_name' => 'Lamar Brown',
        'grade' => 94, 'national_rank' => 1, 'committed_team_id' => 99,
        'high_school' => 'University Laboratory School', 'hometown_city' => 'Baton Rouge',
        'hometown_state' => 'LA', 'position_id' => 7,
    ]);

    Livewire::test('recruiting')
        ->set('class', 2026)
        ->assertSee('Lamar Brown')
        ->assertSee('University Laboratory School')
        ->assertSee('94');
});

it('renders team classes with a linkable school', function () {
    // Regression: a constrained eager load that omits `slug` — the Team route
    // key — makes route() fail with "missing required parameter", which reads
    // like a null relation but is actually a missing column.
    Recruit::create([
        'espn_id' => 1, 'recruiting_class' => 2026, 'display_name' => 'A Prospect',
        'grade' => 94, 'national_rank' => 1, 'committed_team_id' => 99,
    ]);

    Livewire::test('recruiting')
        ->set('class', 2026)
        ->set('view', 'teams')
        ->assertOk()
        ->assertSee('LSU Tigers');
});

it('shows an empty state for a class with nothing ingested', function () {
    Livewire::test('recruiting')
        ->set('class', 2030)
        ->assertOk()
        ->assertSee('No prospects');
});
