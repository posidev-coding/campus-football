<?php

use App\Models\AthleteTeamSeason;
use App\Models\Position;
use App\Models\Team;
use App\Services\Espn\Sync\SyncRosters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);

    Team::factory()->create(['id' => 2633, 'display_name' => 'Tennessee']);
});

/**
 * A roster payload in ESPN's shape: athletes grouped by side, each carrying
 * its position inline.
 *
 * @param  array<int, array{0: int, 1: array<string, mixed>}>  $athletes  [athlete id, position]
 * @return array<string, mixed>
 */
function rosterPayload(array $athletes): array
{
    return [
        'season' => ['year' => 2026],
        'athletes' => [[
            'position' => 'offense',
            'items' => array_map(fn (array $athlete) => [
                'id' => (string) $athlete[0],
                'fullName' => "Athlete {$athlete[0]}",
                'position' => $athlete[1],
            ], $athletes),
        ]],
    ];
}

/** @return array<string, mixed> */
function rosterPosition(int $id, string $name, string $abbreviation, ?int $parentId = 70): array
{
    return array_filter([
        'id' => (string) $id,
        'name' => $name,
        'displayName' => $name,
        'abbreviation' => $abbreviation,
        'slug' => strtolower($abbreviation),
        'parent' => $parentId === null ? null : ['id' => (string) $parentId],
    ], fn ($value) => $value !== null);
}

/**
 * Every query against the positions table while the callback runs.
 *
 * @return array{reads: int, writes: int}
 */
function positionQueries(callable $run): array
{
    $reads = 0;
    $writes = 0;

    DB::listen(function ($query) use (&$reads, &$writes) {
        $sql = strtolower(ltrim($query->sql));

        if (! str_contains($sql, '`positions`')) {
            return;
        }

        str_starts_with($sql, 'select') ? $reads++ : $writes++;
    });

    $run();

    return ['reads' => $reads, 'writes' => $writes];
}

it('writes each distinct position once, not once per athlete', function () {
    /*
     * 2,471 ms a team on 2026-11-10, all 138 of them: every athlete ran a
     * SELECT plus a conditional write to maintain a table of a few dozen rows.
     * Twelve athletes across three positions must cost one read and three
     * writes — a count proportional to the positions, never to the roster.
     */
    $qb = rosterPosition(8, 'Quarterback', 'QB');
    $wr = rosterPosition(1, 'Wide Receiver', 'WR');
    $te = rosterPosition(7, 'Tight End', 'TE');

    $athletes = [];

    foreach (range(1, 12) as $n) {
        $athletes[] = [4_000_000 + $n, [$qb, $wr, $te][$n % 3]];
    }

    Http::fake(['*roster*' => Http::response(rosterPayload($athletes))]);

    $first = positionQueries(fn () => app(SyncRosters::class)->team(2633, 2026));

    expect($first)->toBe(['reads' => 1, 'writes' => 3])
        ->and(Position::count())->toBe(3)
        ->and(AthleteTeamSeason::where('team_id', 2633)->count())->toBe(12)
        ->and(AthleteTeamSeason::where('position_id', 8)->count())->toBe(4);

    // Nothing changed upstream, so nothing is written: the next week's run
    // pays for the read and nothing else.
    $second = positionQueries(fn () => app(SyncRosters::class)->team(2633, 2026));

    expect($second)->toBe(['reads' => 1, 'writes' => 0]);
});

it('still updates a position ESPN renamed, so the cache cannot freeze the table', function () {
    Position::create(['id' => 8, 'name' => 'QB', 'abbreviation' => 'QB', 'slug' => 'qb', 'parent_id' => 70]);

    Http::fake(['*roster*' => Http::response(rosterPayload([
        [4_000_001, rosterPosition(8, 'Quarterback', 'QB')],
        [4_000_002, rosterPosition(8, 'Quarterback', 'QB')],
    ]))]);

    $queries = positionQueries(fn () => app(SyncRosters::class)->team(2633, 2026));

    expect(Position::find(8)->name)->toBe('Quarterback')
        ->and($queries)->toBe(['reads' => 1, 'writes' => 1]);
});

it('records a parent only when ESPN carries one, and never invents a name it lacks', function () {
    Http::fake(['*roster*' => Http::response(rosterPayload([
        [4_000_001, ['id' => '45', 'abbreviation' => 'ATH']],
        [4_000_002, rosterPosition(9, 'Running Back', 'RB', parentId: null)],
    ]))]);

    app(SyncRosters::class)->team(2633, 2026);

    // The "Position {id}" fallback is the existing guard for a payload with
    // neither name — labelled as unknown, never dressed up as a real one.
    expect(Position::find(45)->name)->toBe('Position 45')
        ->and(Position::find(45)->parent_id)->toBeNull()
        ->and(Position::find(9)->parent_id)->toBeNull();
});
