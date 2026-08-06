<?php

use App\Models\Position;
use App\Models\Recruit;
use App\Models\RecruitSchool;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Services\Espn\Sync\SyncRecruiting;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);

    $this->lsu = Team::factory()->create([
        'id' => 99,
        'slug' => 'lsu-tigers',
        // `location` pinned alongside the display name: the factory generates a
        // random city for it, and the team-classes table renders placeName().
        'location' => 'LSU',
        'display_name' => 'LSU Tigers',
        'abbreviation' => 'LSU',
    ]);

    /*
     * A membership row, because the screen's scope filter resolves FBS through
     * `team_seasons`. Without one, `Scope::teamIds()` returns an EMPTY list —
     * not "everyone" — and every committed prospect is filtered out.
     */
    TeamSeason::create([
        'team_id' => 99, 'season_year' => 2026, 'classification' => 'FBS',
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

/**
 * A class page shaped the way ESPN actually serves one.
 *
 * Items carry BOTH a `$ref` and the whole document — diffed live, the key sets
 * are identical — which is what lets the sync read them in place instead of
 * spending a request per prospect. The old fixture returned bare refs, so it
 * could not have caught the cost bug it was meant to describe.
 *
 * `$pages` splits the recruits across pages so the walk past page one is
 * exercised; the previous fixture was always `pageCount => 1`.
 */
function fakeRecruitingClass(array $recruits, int $pages = 1): void
{
    $chunks = array_values(array_chunk($recruits, (int) ceil(max(count($recruits), 1) / $pages)));

    // Built in a loop, not spread into pushResponse() — its second argument is
    // `$times`, so `pushResponse(...$chunks)` quietly passed page two as a
    // repeat count and the walk only ever saw page one.
    $sequence = Http::sequence();

    foreach ($chunks as $chunk) {
        $sequence->pushResponse(Http::response([
            'count' => count($recruits),
            'pageCount' => count($chunks),
            'items' => array_map(
                fn (array $r, int $i) => array_merge(['$ref' => "https://espn/recruits/{$i}"], $r),
                $chunk,
                array_keys($chunk)
            ),
        ]));
    }

    Http::fake([
        '*recruiting*athletes*' => $sequence,
        // Anything reaching a per-prospect document is a regression: the whole
        // point is that the collection already carried it.
        'https://espn/recruits/*' => Http::response(['unexpected' => true], 500),
    ]);
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
        ->set('year', 2026)
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
        ->set('year', 2026)
        ->set('view', 'teams')
        ->assertOk()
        // The place, not the mascot — the table is scanned rather than read.
        ->assertSee('LSU');
});

it('reads a whole class in pages, and never re-fetches an item', function () {
    /*
     * The cost fix, pinned. ESPN serves 1,000 a page and each item already
     * carries its full document, so a class is six requests rather than ~5,200.
     * The per-prospect stub in the fixture 500s, so following a `$ref` would
     * fail loudly instead of quietly costing a request.
     */
    fakeRecruitingClass([
        recruitPayload(1, 'First Prospect', 1, 95),
        recruitPayload(2, 'Second Prospect', 2, 94),
        recruitPayload(3, 'Third Prospect', 3, 93),
        recruitPayload(4, 'Fourth Prospect', 4, 92),
    ], pages: 2);

    expect(app(SyncRecruiting::class)->handle(2026))->toBe(4)
        ->and(Recruit::count())->toBe(4);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'limit=1000'));
});

it('stores the school interest list, including a school we do not carry', function () {
    /*
     * `schools[]` is an interest list, not a visit list — only 6% of live
     * entries carry a date. A school outside our set still counts: dropping it
     * would misreport how many were in on a prospect.
     */
    $payload = recruitPayload(1, 'Lamar Brown', 1, 94);
    $payload['schools'] = [
        ['status' => ['id' => 3, 'description' => 'Signed'], 'visit' => '2025-06-20T07:00Z',
            'team' => ['$ref' => 'https://espn/seasons/2026/teams/99']],
        ['status' => ['id' => 0, 'description' => 'Undecided'],
            'team' => ['$ref' => 'https://espn/seasons/2026/teams/88888']],
    ];

    fakeRecruitingClass([$payload]);
    app(SyncRecruiting::class)->handle(2026);

    $schools = RecruitSchool::orderBy('espn_team_id')->get();

    expect($schools)->toHaveCount(2)
        ->and($schools[0]->team_id)->toBe(99)
        ->and($schools[0]->visited_on->toDateString())->toBe('2025-06-20')
        // Kept, with ESPN's id, but no FK — we do not carry team 88888.
        ->and($schools[1]->team_id)->toBeNull()
        ->and($schools[1]->espn_team_id)->toBe(88888);
});

it('drops a visit date that cannot be true', function () {
    /*
     * Seven rows in the live 2026 class carry the year 2205 — an ESPN typo for
     * 2025 — and MySQL's timestamp overflows past 2038-01-19. Dropping beats
     * guessing the intended year, and beats printing a visit two centuries out.
     */
    $payload = recruitPayload(1, 'Lamar Brown', 1, 94);
    $payload['schools'] = [[
        'status' => ['id' => 3, 'description' => 'Signed'],
        'visit' => '2205-06-13T07:00Z',
        'team' => ['$ref' => 'https://espn/seasons/2026/teams/99'],
    ]];

    fakeRecruitingClass([$payload]);
    app(SyncRecruiting::class)->handle(2026);

    expect(RecruitSchool::sole()->visited_on)->toBeNull();
});

it('re-syncing does not duplicate the interest list', function () {
    // The unique is keyed on ESPN's team id rather than the nullable FK: MySQL
    // never matches NULL to NULL, so a school we do not carry was re-inserted
    // on every weekly run.
    $payload = recruitPayload(1, 'Lamar Brown', 1, 94);
    $payload['schools'] = [
        ['status' => ['id' => 3, 'description' => 'Signed'], 'team' => ['$ref' => 'https://espn/seasons/2026/teams/99']],
        ['status' => ['id' => 0, 'description' => 'Undecided'], 'team' => ['$ref' => 'https://espn/seasons/2026/teams/88888']],
    ];

    fakeRecruitingClass([$payload]);

    app(SyncRecruiting::class)->handle(2026);
    $first = RecruitSchool::count();

    fakeRecruitingClass([$payload]);
    app(SyncRecruiting::class)->handle(2026);

    expect(RecruitSchool::count())->toBe($first)->toBe(2);
});

it('keeps an uncommitted prospect, and does not invent a school for them', function () {
    /*
     * 640 of the live 2026 class are Undecided, and their per-school statuses
     * all share id 0 — matching the commitment on status id alone would pick
     * one of their visits at random and call it a signing.
     */
    $payload = recruitPayload(1, 'Undecided Prospect', 1, 90);
    $payload['status'] = ['id' => 0, 'description' => 'Undecided'];
    $payload['schools'] = [
        ['status' => ['id' => 0, 'description' => 'Undecided'], 'team' => ['$ref' => 'https://espn/seasons/2026/teams/99']],
    ];

    fakeRecruitingClass([$payload]);
    app(SyncRecruiting::class)->handle(2026);

    $recruit = Recruit::sole();

    expect($recruit->committed_team_id)->toBeNull()
        ->and($recruit->status)->toBe('Undecided');

    // And the screen still shows them — a conference scope filters on TEAMS,
    // which an uncommitted prospect does not have.
    Livewire::test('recruiting')
        ->set('year', 2026)
        ->assertSee('Undecided Prospect');
});

it('carries no visible heading, like every other League screen', function () {
    expect(Livewire::test('recruiting')->set('year', 2026)->html())
        ->toContain('<h1 class="sr-only">Recruiting</h1>')
        ->not->toContain('>Recruiting</flux:heading>');
});

it('shows an empty state for a class with nothing ingested', function () {
    Livewire::test('recruiting')
        ->set('year', 2030)
        ->assertOk()
        ->assertSee('No prospects');
});
