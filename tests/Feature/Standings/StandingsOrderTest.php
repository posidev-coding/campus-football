<?php

use App\Enums\StandingSource;
use App\Models\Conference;
use App\Models\Standing;
use App\Models\Team;

/*
 * A team that has won a game outranks a team that has not played one.
 *
 * That is the whole rule, and the standings screen broke it for the entire
 * first weekend of a season. ESPN seeds only the teams that have PLAYED and
 * publishes `playoffSeed: 0` for the rest, so ordering on the raw seed read
 * every team that had not kicked off as the conference's number one.
 *
 * The fixtures below are the live 2026 week-1 ACC, read off ESPN's feed on
 * 2026-09-01: four 1-0 teams seeded 1-4, twelve 0-0 teams seeded 0, and one
 * 0-1 team seeded 5 — which ESPN itself renders winners, then unplayed, then
 * the loser.
 */

/** One ESPN standings row, with every column a sort key reads pinned. */
function standingRow(int $teamId, string $name, int $conferenceId, array $attributes = []): Team
{
    $team = Team::factory()->create([
        'id' => $teamId, 'location' => $name, 'display_name' => "{$name} Team", 'slug' => str($name)->slug()->value(),
    ]);

    Standing::create(array_merge([
        'season_year' => 2026,
        'conference_id' => $conferenceId,
        'team_id' => $teamId,
        'source' => StandingSource::Espn,
        'overall_wins' => 0, 'overall_losses' => 0, 'overall_ties' => 0,
        'conf_wins' => 0, 'conf_losses' => 0, 'conf_ties' => 0,
        // ESPN's own sentinels for a team with nothing in the books. All three
        // are indistinguishable from a team that has lost everything, which is
        // the trap this file exists to hold.
        'win_pct' => 0.0, 'conf_win_pct' => 0.0, 'playoff_seed' => 0,
        'point_differential' => 0,
    ], $attributes));

    return $team;
}

/** @return list<string> */
function orderedNames(int $conferenceId, int $year = 2026): array
{
    return Standing::query()
        ->fromEspn()
        ->where('season_year', $year)
        ->where('conference_id', $conferenceId)
        ->inStandingsOrder()
        ->with('team:id,location')
        ->get()
        ->map(fn (Standing $standing) => $standing->team->location)
        ->all();
}

beforeEach(function () {
    Conference::factory()->create(['id' => 1, 'name' => 'Atlantic Coast Conference', 'short_name' => 'ACC']);
});

it('puts a 1-0 team above a team that has not played', function () {
    standingRow(41, 'Nobody', 1);

    standingRow(258, 'Winner', 1, [
        'overall_wins' => 1, 'win_pct' => 1.0, 'playoff_seed' => 1, 'point_differential' => 21,
    ]);

    expect(orderedNames(1))->toBe(['Winner', 'Nobody']);
});

it('puts a team that has not played above a team that has lost', function () {
    // The other half of the same rule, and the half a naive "seeded teams
    // first" fix gets backwards: NC State carries seed 5 and belongs last.
    standingRow(152, 'Loser', 1, [
        'overall_losses' => 1, 'win_pct' => 0.0, 'playoff_seed' => 5, 'point_differential' => -17,
    ]);

    standingRow(41, 'Nobody', 1);

    expect(orderedNames(1))->toBe(['Nobody', 'Loser']);
});

it('orders a half-played opening weekend the way ESPN renders it', function () {
    // Winners in seed order, the twelve who have not kicked off, then the loss.
    foreach ([[258, 'Virginia', 1], [52, 'Florida State', 2], [153, 'North Carolina', 3], [24, 'Stanford', 4]] as [$id, $name, $seed]) {
        standingRow($id, $name, 1, [
            'overall_wins' => 1, 'win_pct' => 1.0, 'playoff_seed' => $seed, 'point_differential' => 30 - $seed,
        ]);
    }

    standingRow(25, 'California', 1);
    standingRow(59, 'Georgia Tech', 1);

    standingRow(97, 'NC State', 1, [
        'overall_losses' => 1, 'win_pct' => 0.0, 'playoff_seed' => 5, 'point_differential' => -17,
    ]);

    expect(orderedNames(1))->toBe([
        'Virginia', 'Florida State', 'North Carolina', 'Stanford',
        'California', 'Georgia Tech',
        'NC State',
    ]);
});

it('follows ESPN once every team in the conference is seeded', function () {
    /*
     * The 2025 SEC at 6-2: Texas is 9-3 and seeded above two 10-2 teams,
     * because ESPN is applying head-to-head and we cannot. A sort that ranked
     * on records would put Texas third, so the seed has to keep leading the
     * moment the whole conference carries one.
     */
    Conference::factory()->create(['id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC']);

    $teams = [
        [251, 'Texas', 5, 9, 3],
        [201, 'Oklahoma', 6, 10, 2],
        [238, 'Vanderbilt', 7, 10, 2],
    ];

    foreach ($teams as [$id, $name, $seed, $wins, $losses]) {
        standingRow($id, $name, 8, [
            'overall_wins' => $wins, 'overall_losses' => $losses,
            'conf_wins' => 6, 'conf_losses' => 2,
            'win_pct' => round($wins / ($wins + $losses), 4), 'conf_win_pct' => 0.75,
            'playoff_seed' => $seed, 'point_differential' => 100,
        ]);
    }

    expect(orderedNames(8))->toBe(['Texas', 'Oklahoma', 'Vanderbilt']);
});

it('judges the seed per conference, not per league', function () {
    // An ACC that has half kicked off must not cost the SEC its seeds, and a
    // fully seeded SEC must not re-float the ACC's unplayed teams.
    Conference::factory()->create(['id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC']);

    standingRow(41, 'Nobody', 1);
    standingRow(258, 'Winner', 1, [
        'overall_wins' => 1, 'win_pct' => 1.0, 'playoff_seed' => 1, 'point_differential' => 21,
    ]);

    standingRow(251, 'Texas', 8, [
        'overall_wins' => 9, 'overall_losses' => 3, 'conf_wins' => 6, 'conf_losses' => 2,
        'win_pct' => 0.75, 'conf_win_pct' => 0.75, 'playoff_seed' => 5, 'point_differential' => 100,
    ]);
    standingRow(201, 'Oklahoma', 8, [
        'overall_wins' => 10, 'overall_losses' => 2, 'conf_wins' => 6, 'conf_losses' => 2,
        'win_pct' => 0.833, 'conf_win_pct' => 0.75, 'playoff_seed' => 6, 'point_differential' => 100,
    ]);

    expect(orderedNames(1))->toBe(['Winner', 'Nobody'])
        ->and(orderedNames(8))->toBe(['Texas', 'Oklahoma']);
});

it('ranks on conference record before overall record', function () {
    // Conference standings are a conference table: a 4-0 team that has not
    // opened league play sits behind a 1-0 conference record, .500 or not.
    standingRow(258, 'League Leader', 1, [
        'overall_wins' => 3, 'overall_losses' => 1, 'conf_wins' => 1, 'conf_losses' => 0,
        'win_pct' => 0.75, 'conf_win_pct' => 1.0, 'playoff_seed' => 2, 'point_differential' => 20,
    ]);

    standingRow(52, 'Unbeaten', 1, [
        'overall_wins' => 4, 'win_pct' => 1.0, 'playoff_seed' => 1, 'point_differential' => 80,
    ]);

    // A third team still unseeded keeps the seed out of it, so this asserts
    // the record keys rather than ESPN's order.
    standingRow(41, 'Nobody', 1);

    expect(orderedNames(1))->toBe(['League Leader', 'Unbeaten', 'Nobody']);
});
