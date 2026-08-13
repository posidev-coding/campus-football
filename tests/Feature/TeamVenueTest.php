<?php

use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\Venue;
use App\Support\TeamVenue;
use Illuminate\Support\Facades\DB;

/*
 * The home-stadium inference: the mode of venue_id across a team's
 * NON-NEUTRAL home games. Verified against live data before it shipped —
 * with the neutral filter on, every sampled program maps to exactly one
 * venue — so these tests hold the filter and the fallback, which are the
 * two ways the inference could quietly rot.
 */

beforeEach(function () {
    $this->season = Season::factory()->create([
        'year' => 2025, 'type' => Season::REGULAR,
        'start_date' => '2025-08-30', 'end_date' => '2025-12-13',
    ]);

    Team::factory()->create(['id' => 2633, 'slug' => 'tennessee-volunteers', 'location' => 'Tennessee']);
    Team::factory()->create(['id' => 96, 'slug' => 'kentucky-wildcats', 'location' => 'Kentucky']);

    Venue::create(['id' => 3843, 'name' => 'Neyland Stadium', 'city' => 'Knoxville', 'state' => 'TN']);
    Venue::create(['id' => 5348, 'name' => 'Mercedes-Benz Stadium', 'city' => 'Atlanta', 'state' => 'GA']);
});

it('names the stadium the team actually plays home games in', function () {
    foreach (['2025-09-06 19:00:00', '2025-09-13 15:30:00'] as $kickoff) {
        Game::factory()->create([
            'season_id' => $this->season->id,
            'home_team_id' => 2633, 'away_team_id' => 96,
            'venue_id' => 3843, 'kickoff_at' => $kickoff, 'completed' => true,
        ]);
    }

    // One hosted game elsewhere must not outvote the real building.
    Game::factory()->create([
        'season_id' => $this->season->id,
        'home_team_id' => 2633, 'away_team_id' => 96,
        'venue_id' => 5348, 'kickoff_at' => '2025-09-20 12:00:00', 'completed' => true,
    ]);

    expect(TeamVenue::nameFor(2633))->toBe('Neyland Stadium');
});

it('never lets neutral-site games vote, however many there are', function () {
    // Two kickoff classics in Atlanta against one true home game: the one
    // real game wins, because neutral rows are excluded rather than outvoted.
    foreach (['2025-08-30 19:30:00', '2025-09-27 19:30:00'] as $kickoff) {
        Game::factory()->create([
            'season_id' => $this->season->id,
            'home_team_id' => 2633, 'away_team_id' => 96,
            'venue_id' => 5348, 'neutral_site' => true,
            'kickoff_at' => $kickoff, 'completed' => true,
        ]);
    }

    Game::factory()->create([
        'season_id' => $this->season->id,
        'home_team_id' => 2633, 'away_team_id' => 96,
        'venue_id' => 3843, 'kickoff_at' => '2025-09-06 19:00:00', 'completed' => true,
    ]);

    expect(TeamVenue::nameFor(2633))->toBe('Neyland Stadium');
});

it('says nothing for a team with only road games, rather than borrowing a stadium', function () {
    // 2633 visits Kentucky: that venue is Kentucky's answer, not Tennessee's.
    Game::factory()->create([
        'season_id' => $this->season->id,
        'home_team_id' => 96, 'away_team_id' => 2633,
        'venue_id' => 5348, 'kickoff_at' => '2025-10-25 19:00:00', 'completed' => true,
    ]);

    expect(TeamVenue::nameFor(2633))->toBeNull()
        ->and(TeamVenue::nameFor(96))->toBe('Mercedes-Benz Stadium');
});

it('says nothing when the games table has nothing to say', function () {
    // Null means no data — the caller falls back to the school's own name,
    // never to a guessed venue.
    expect(TeamVenue::nameFor(2633))->toBeNull();
});

it('caches the answer as a plain string, once', function () {
    Game::factory()->create([
        'season_id' => $this->season->id,
        'home_team_id' => 2633, 'away_team_id' => 96,
        'venue_id' => 3843, 'kickoff_at' => '2025-09-06 19:00:00', 'completed' => true,
    ]);

    expect(TeamVenue::nameFor(2633))->toBe('Neyland Stadium');

    DB::enableQueryLog();

    expect(TeamVenue::nameFor(2633))->toBe('Neyland Stadium')
        ->and(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
});
