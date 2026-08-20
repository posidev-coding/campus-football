<?php

use App\Models\Conference;
use App\Models\Contest;
use App\Models\TeamSeason;
use App\Services\Contests\SuggestSlate;
use Illuminate\Support\Facades\Http;

/*
 * The themed-slate admission rules behind the flavored public rooms. A
 * filter NARROWS the standard Saturday candidate pool and does nothing
 * else: spreadless games still drop through the quality score, publish
 * still refuses a short board, and a filter that empties the pool means a
 * room that never spawns — never a thin board that lies.
 */

beforeEach(function () {
    Http::fake();
    $this->travelTo('2026-09-02 12:00:00');
});

it('admits only games with a ranked side to a ranked board', function () {
    [$season, $week] = pickemSeasonWeek();
    $contest = Contest::factory()->create([
        'settings' => ['slate_filter' => 'ranked', 'slate_size' => 2],
    ]);

    $ranked = collect(range(1, 3))->map(function (int $i) use ($season, $week) {
        $game = pickemGame($season, $week, ['home_rank' => $i]);
        pickemOdd($game);

        return $game;
    });

    collect(range(1, 3))->each(function () use ($season, $week) {
        pickemOdd(pickemGame($season, $week));
    });

    $suggest = new SuggestSlate;
    $board = collect($suggest->for($contest, $week));

    expect($board)->toHaveCount(2)
        ->and($ranked->pluck('id'))->toContain(...$board->pluck('game_id'))
        // The count a dynamic room freezes its size from agrees with what
        // the suggester can actually draw — same pipeline by construction.
        ->and($suggest->viableCount($contest, $week))->toBe(3);

    Http::assertNothingSent();
});

it('draws the primetime line at 7pm Eastern, per game and never in SQL', function () {
    [$season, $week] = pickemSeasonWeek();
    $contest = Contest::factory()->create([
        'settings' => ['slate_filter' => 'primetime', 'slate_size' => 1],
    ]);

    // 23:00 UTC is 7:00pm ET in September — the first night kick.
    $night = pickemGame($season, $week, ['kickoff_at' => '2026-09-05 23:00:00']);
    pickemOdd($night);

    // 22:59 UTC is 6:59pm ET: an afternoon game by one minute.
    $dusk = pickemGame($season, $week, ['kickoff_at' => '2026-09-05 22:59:00']);
    pickemOdd($dusk);

    $board = collect((new SuggestSlate)->for($contest, $week));

    expect($board->pluck('game_id')->all())->toBe([$night->id]);
});

it('admits a conference card by season membership, either side of the ball', function () {
    [$season, $week] = pickemSeasonWeek();
    $sec = Conference::factory()->create(['abbreviation' => 'sec', 'short_name' => 'SEC']);
    $contest = Contest::factory()->create([
        'settings' => ['slate_filter' => 'conference', 'filter_conference' => 'sec', 'slate_size' => 1],
    ]);

    $inConference = pickemGame($season, $week);
    pickemOdd($inConference);

    // Membership is season-scoped through team_seasons, never a scalar on
    // teams — and the ROAD side qualifying is the September reality, where
    // a conference card is mostly its members' non-conference dates.
    TeamSeason::create([
        'team_id' => $inConference->away_team_id,
        'season_year' => (int) $season->year,
        'conference_id' => $sec->id,
    ]);

    $outsider = pickemGame($season, $week);
    pickemOdd($outsider);

    $board = collect((new SuggestSlate)->for($contest, $week));

    expect($board->pluck('game_id')->all())->toBe([$inConference->id]);
});

it('returns an empty board from a filter that empties the pool', function () {
    // Lined but unranked: the ranked filter leaves nothing, and the honest
    // answer is [] — the spawner reads viableCount and opens no room.
    [$season, $week] = pickemSeasonWeek();
    $contest = Contest::factory()->create([
        'settings' => ['slate_filter' => 'ranked', 'slate_size' => 5],
    ]);

    pickemOdd(pickemGame($season, $week));

    $suggest = new SuggestSlate;

    expect($suggest->for($contest, $week))->toBe([])
        ->and($suggest->viableCount($contest, $week))->toBe(0);
});
