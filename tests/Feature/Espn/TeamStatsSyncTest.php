<?php

use App\Models\Athlete;
use App\Models\AthleteTeamSeason;
use App\Models\Position;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\TeamSeasonStat;
use App\Services\Espn\Sync\SyncTeamStats;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Season::factory()->create(['year' => 2022, 'type' => Season::REGULAR]);
    Team::factory()->create(['id' => 61, 'display_name' => 'Georgia Bulldogs']);
    TeamSeason::create(['team_id' => 61, 'season_year' => 2022, 'classification' => 'FBS']);

    // We carry a handful of positions, seeded from current rosters.
    Position::create(['id' => 8, 'name' => 'Quarterback', 'abbreviation' => 'QB']);
});

it('keeps the national rank ESPN publishes on every stat', function () {
    // Discarding it would mean either no national stats screen or ranking 136
    // teams ourselves on every read. ESPN has already done it.
    Http::fake(['*statistics*' => Http::response([
        'splits' => ['categories' => [[
            'name' => 'scoring',
            'stats' => [[
                'name' => 'totalPoints',
                'displayName' => 'Total Points',
                'displayValue' => '415',
                'value' => 415,
                'rank' => 21,
            ]],
        ]]],
    ]), '*' => Http::response([])]);

    app(SyncTeamStats::class)->team(61, 2022);

    $row = TeamSeasonStat::where('team_id', 61)->where('category', 'scoring')->first();

    expect($row->stat('totalPoints')['rank'])->toBe(21)
        ->and($row->stat('totalPoints')['display'])->toBe('415')
        ->and($row->stat('totalPoints')['label'])->toBe('Total Points');
});

it('drops an unknown position rather than aborting the whole season', function () {
    /*
     * Regression: `positions` holds 32 rows seeded from current rosters, but
     * ESPN numbers positions up to at least 264, and a historical athlete can
     * name one we have never seen. Writing it blind fails the foreign key —
     * which killed the entire 2022 backfill, 136 teams, over one player.
     *
     * Same rule as unknown teams in the rankings sync: drop the field, keep the
     * row.
     */
    Http::fake([
        '*leaders*' => Http::response([
            'categories' => [[
                'name' => 'passingYards',
                'leaders' => [[
                    'value' => 3200,
                    'displayValue' => '3200',
                    'athlete' => ['$ref' => 'http://x/athletes/999111?lang=en'],
                ]],
            ]],
        ]),
        '*athletes/999111*' => Http::response([
            'fullName' => 'Historical Quarterback',
            // A position id we do not carry.
            'position' => ['id' => 264],
        ]),
        '*' => Http::response([]),
    ]);

    expect(fn () => app(SyncTeamStats::class)->team(61, 2022))->not->toThrow(Throwable::class);

    expect(Athlete::whereKey(999111)->exists())->toBeTrue();

    $row = AthleteTeamSeason::where('athlete_id', 999111)->where('season_year', 2022)->first();

    expect($row)->not->toBeNull()
        ->and($row->position_id)->toBeNull()
        ->and($row->team_id)->toBe(61);
});

it('keeps a position it does carry', function () {
    Http::fake([
        '*leaders*' => Http::response([
            'categories' => [[
                'name' => 'passingYards',
                'leaders' => [[
                    'value' => 3200,
                    'displayValue' => '3200',
                    'athlete' => ['$ref' => 'http://x/athletes/999222?lang=en'],
                ]],
            ]],
        ]),
        '*athletes/999222*' => Http::response([
            'fullName' => 'Known Quarterback',
            'position' => ['id' => 8],
        ]),
        '*' => Http::response([]),
    ]);

    app(SyncTeamStats::class)->team(61, 2022);

    expect(AthleteTeamSeason::where('athlete_id', 999222)->value('position_id'))->toBe(8);
});
