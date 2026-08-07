<?php

use App\Models\Conference;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Support\TeamGlance;
use Illuminate\Support\Facades\DB;

/*
 * "6th in SEC" is two facts from two tables, and they have to describe the
 * same grouping.
 *
 * ESPN files a team's standings under EVERY group it belongs to, so the SEC
 * appears in `standings` twice over: as itself, and inside the 138-team "FBS"
 * division group. The Sun Belt and SWAC appear a third time, inside an
 * East/West half. Counting a position over `standings.conference_id` therefore
 * counted a team several times and kept whichever group was read last, while
 * the label beside it always came from `team_seasons`.
 *
 * Both live failures are reproduced below, because they look nothing alike:
 * one printed an absurd number and the other printed a plausible one.
 */
beforeEach(function () {
    Conference::factory()->create([
        'id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC', 'abbreviation' => 'sec',
    ]);

    // ESPN's division group, and not a league: `is_conference` is false on it,
    // as it is on the Sun Belt's East/West halves.
    Conference::factory()->create([
        'id' => 80, 'name' => 'NCAA Division I FBS', 'short_name' => 'FBS',
        'abbreviation' => 'fbs', 'is_conference' => false,
    ]);
});

/** A team in the SEC, with the standing ESPN publishes under BOTH its groups. */
function secTeam(int $id, string $name, int $wins, int $losses, int $confWins, int $confLosses): Team
{
    $team = Team::factory()->create([
        'id' => $id, 'display_name' => $name, 'slug' => str($name)->slug()->value(),
    ]);

    TeamSeason::create(['team_id' => $id, 'season_year' => 2025, 'conference_id' => 8, 'classification' => 'FBS']);

    foreach ([8, 80] as $group) {
        Standing::create([
            'season_year' => 2025, 'conference_id' => $group, 'team_id' => $id, 'source' => 'espn',
            'overall_wins' => $wins, 'overall_losses' => $losses, 'overall_ties' => 0,
            'conf_wins' => $confWins, 'conf_losses' => $confLosses, 'conf_ties' => 0,
            'win_pct' => $wins + $losses > 0 ? round($wins / ($wins + $losses), 4) : null,
            'conf_win_pct' => $confWins + $confLosses > 0 ? round($confWins / ($confWins + $confLosses), 4) : null,
        ]);
    }

    return $team;
}

it('counts a position within the conference, not the division group ESPN also files it under', function () {
    /*
     * The reported bug: Tennessee's hero read "130th in SEC", because the
     * 138-team FBS group was read after the SEC and overwrote it. Three teams
     * stand in for 138 here — what matters is that the position never exceeds
     * the conference and never comes from the larger group.
     */
    secTeam(61, 'Georgia Bulldogs', 11, 1, 8, 0);
    secTeam(2633, 'Tennessee Volunteers', 8, 4, 4, 4);
    secTeam(2, 'Auburn Tigers', 5, 7, 2, 6);

    // Two teams sharing the FBS group but not the conference, ranked below
    // everyone — the shape that inflated the count.
    Conference::factory()->create(['id' => 5, 'name' => 'Big Ten Conference', 'short_name' => 'Big Ten']);

    foreach ([[194, 'Ohio State Buckeyes'], [130, 'Michigan Wolverines']] as [$id, $name]) {
        Team::factory()->create(['id' => $id, 'display_name' => $name, 'slug' => str($name)->slug()->value()]);
        TeamSeason::create(['team_id' => $id, 'season_year' => 2025, 'conference_id' => 5, 'classification' => 'FBS']);
        Standing::create([
            'season_year' => 2025, 'conference_id' => 80, 'team_id' => $id, 'source' => 'espn',
            'overall_wins' => 1, 'overall_losses' => 11, 'conf_wins' => 0, 'conf_losses' => 8,
            'win_pct' => 0.0833, 'conf_win_pct' => 0.0,
        ]);
    }

    $positions = TeamGlance::standingPositions(2025);

    expect($positions[61])->toBe(1)
        ->and($positions[2633])->toBe(2)
        ->and($positions[2])->toBe(3)
        /*
         * Their own conference publishes no standings here, so they place
         * nowhere — never 4th and 5th "in the Big Ten" off the back of a group
         * that is not the Big Ten. Silence beats a number from the wrong list.
         */
        ->and($positions)->not->toHaveKey(194)
        ->and($positions)->not->toHaveKey(130);
});

it('does not let a divisional half stand in for the whole conference', function () {
    /*
     * The failure nobody would have thought to query: every 2025 Sun Belt team
     * read its EAST/WEST position, so a 14-team conference showed two 1sts,
     * two 2nds, and so on down to two 7ths. Nothing about that looks wrong on
     * a card, which is why it survived three screens.
     */
    Conference::factory()->create(['id' => 37, 'name' => 'Sun Belt Conference', 'short_name' => 'Sun Belt']);
    Conference::factory()->create(['id' => 167, 'name' => 'Sun Belt - East', 'is_conference' => false]);
    Conference::factory()->create(['id' => 168, 'name' => 'Sun Belt - West', 'is_conference' => false]);

    $halves = [167 => [2026, 2429, 290], 168 => [2032, 309, 2433]];
    $wins = 12;

    foreach ($halves as $half => $ids) {
        foreach ($ids as $id) {
            Team::factory()->create(['id' => $id, 'display_name' => "Team {$id}", 'slug' => "team-{$id}"]);
            TeamSeason::create(['team_id' => $id, 'season_year' => 2025, 'conference_id' => 37, 'classification' => 'FBS']);

            foreach ([37, $half] as $group) {
                Standing::create([
                    'season_year' => 2025, 'conference_id' => $group, 'team_id' => $id, 'source' => 'espn',
                    'overall_wins' => $wins, 'overall_losses' => 12 - $wins,
                    'conf_wins' => $wins, 'conf_losses' => 12 - $wins,
                    'win_pct' => round($wins / 12, 4), 'conf_win_pct' => round($wins / 12, 4),
                ]);
            }

            $wins -= 2;
        }
    }

    $positions = TeamGlance::standingPositions(2025);
    $ordered = collect($halves)->flatten()->map(fn (int $id) => $positions[$id] ?? null);

    // Six teams, six positions, one conference.
    expect($ordered->all())->toBe([1, 2, 3, 4, 5, 6])
        ->and($ordered->duplicates())->toBeEmpty();
});

it('gives no position at all in a conference that has not kicked off', function () {
    /*
     * Every team 0-0, which is the whole league from February to late August.
     * What falls out of the tiebreaks then is insertion order, so "1st in the
     * SEC" would be alphabetical luck wearing a standing's clothes.
     */
    secTeam(61, 'Georgia Bulldogs', 0, 0, 0, 0);
    secTeam(2633, 'Tennessee Volunteers', 0, 0, 0, 0);
    secTeam(2, 'Auburn Tigers', 0, 0, 0, 0);

    expect(TeamGlance::standingPositions(2025))->toBe([]);
});

it('positions a conference that has started even while another has not', function () {
    // Judged per conference, not per league: an FCS conference opening a week
    // later must not silence a division already three games in.
    secTeam(61, 'Georgia Bulldogs', 3, 0, 1, 0);
    secTeam(2633, 'Tennessee Volunteers', 2, 1, 0, 1);

    Conference::factory()->create(['id' => 20, 'name' => 'Big Sky Conference', 'short_name' => 'Big Sky']);

    foreach ([[16, 'Sacramento State Hornets'], [147, 'Montana State Bobcats']] as [$id, $name]) {
        Team::factory()->create(['id' => $id, 'display_name' => $name, 'slug' => str($name)->slug()->value()]);
        TeamSeason::create(['team_id' => $id, 'season_year' => 2025, 'conference_id' => 20, 'classification' => 'FCS']);
        Standing::create(['season_year' => 2025, 'conference_id' => 20, 'team_id' => $id, 'source' => 'espn']);
    }

    $positions = TeamGlance::standingPositions(2025);

    expect($positions[61])->toBe(1)
        ->and($positions[2633])->toBe(2)
        ->and($positions)->not->toHaveKey(16)
        ->and($positions)->not->toHaveKey(147);
});

it('still costs one query for the whole league', function () {
    secTeam(61, 'Georgia Bulldogs', 11, 1, 8, 0);
    secTeam(2633, 'Tennessee Volunteers', 8, 4, 4, 4);

    // Restricting each team to its own conference rides a subquery, not a
    // second pass. This map is read on every search row and every glance card,
    // so a second query here is a second query per screen.
    DB::enableQueryLog();
    DB::flushQueryLog();

    TeamGlance::standingPositions(2025);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
});
