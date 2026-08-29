<?php

use App\Actions\FollowTeam;
use App\Actions\PublishSlate;
use App\Models\Contest;
use App\Models\Game;
use App\Models\GameOdd;
use App\Models\GamePredictor;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Services\CfbCalendar;
use App\Services\Contests\GameQualityScore;
use App\Support\Cadence;
use App\Support\GameRanks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * The quality snapshot — calibration data, and the one piece of the AI-layer
 * plan with a real deadline.
 *
 * The score's three biggest components ride ESPN feeds that are
 * current-window only: 4,847 completed games across 2021–2025 carry zero
 * matchup_quality and zero odds, against 946 in 2026 that carry both. So the
 * weights can never be back-tested, and the only labeled rows anyone will ever
 * have are the ones written down as slates publish. A slate published before
 * this exists is gone as calibration data permanently.
 *
 * Everything here is about the honesty of that record: absent is null, present
 * and zero is 0.0, and re-pressing publish never rewrites history.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
    GameRanks::flush();
});

/** A scoreable game: pinned Saturday kickoff, one usable current line. */
function qualityGame(array $overrides = []): Game
{
    [$season, $week] = pickemSeasonWeek();

    $game = pickemGame($season, $week, $overrides);
    pickemOdd($game);

    return $game->fresh();
}

// ------------------------------------------------------------- components

it('carries the raw inputs beside the weighted parts, under a version', function () {
    // The raw features are the point. A re-fit is solving for the weights, so
    // a stored product has the answer baked into the question.
    $game = qualityGame(['conference_game' => true, 'home_rank' => 5]);
    GamePredictor::create(['game_id' => $game->id, 'matchup_quality' => 70.0]);
    pickemOdd($game, ['phase' => GameOdd::OPEN, 'spread' => -3.5]);

    $components = GameQualityScore::components($game->fresh());

    expect($components['v'])->toBe(1)
        ->and($components['raw'])->toBe([
            'matchup_quality' => 70.0,
            'spread' => -6.5,
            'open_spread' => -3.5,
            'home_rank' => 5,
            'away_rank' => null,
            'conference_game' => true,
        ]);

    expect(round($components['weighted']['matchup'], 4))->toBe(42.0)
        ->and(round($components['weighted']['tightness'], 4))->toBe(10.7143)
        ->and(round($components['weighted']['movement'], 4))->toBe(2.1429)
        ->and($components['weighted']['rankings'])->toBe(5.0)
        ->and($components['weighted']['conference'])->toBe(5.0);
});

it('leaves an absent signal null rather than scoring it zero', function () {
    // The whole reason the snapshot is worth keeping. Persisting 0.0 for an
    // unrated game would teach a future re-fit that unrated games are BAD
    // games; they are unmeasured, which is not the same thing.
    $game = qualityGame();

    $components = GameQualityScore::components($game);

    expect($components['weighted']['matchup'])->toBeNull()
        ->and($components['raw']['matchup_quality'])->toBeNull()
        ->and($components['weighted']['movement'])->toBeNull()
        ->and($components['raw']['open_spread'])->toBeNull();

    // ...and the score still sums everything the game DOES have.
    expect(GameQualityScore::total($components))->toBe(10.71);
});

it('scores a measured zero as zero', function () {
    // Neither side ranked is an answer GameRanks gives definitively, and a
    // non-conference game is a fact. Both are 0.0, not null.
    $components = GameQualityScore::components(qualityGame());

    expect($components['weighted']['rankings'])->toBe(0.0)
        ->and($components['weighted']['conference'])->toBe(0.0)
        ->and($components['raw']['conference_game'])->toBeFalse();
});

it('is null all the way down when the game cannot be scored', function () {
    [$season, $week] = pickemSeasonWeek();
    $game = pickemGame($season, $week);

    expect(GameQualityScore::components($game))->toBeNull()
        ->and(GameQualityScore::for($game))->toBeNull();
});

it('still answers exactly what for() has always answered', function () {
    $game = qualityGame(['conference_game' => true]);
    GamePredictor::create(['game_id' => $game->id, 'matchup_quality' => 70.0]);

    $game = $game->fresh();

    expect(GameQualityScore::for($game))
        ->toBe(GameQualityScore::total(GameQualityScore::components($game)))
        ->toBe(57.71);
});

// -------------------------------------------------------------- the snapshot

/** A publishable Classic draft of exactly $count games. */
function qualitySlate(Contest $contest, int $count): Slate
{
    [$season, $week] = pickemSeasonWeek();

    $contest->update(['settings' => ['slate_size' => $count]]);

    $slate = Slate::factory()->create(['contest_id' => $contest->id, 'week_id' => $week->id]);

    foreach (range(1, $count) as $i) {
        $game = qualityGame();

        SlateGame::factory()->create([
            'slate_id' => $slate->id,
            'game_id' => $game->id,
            'tier' => null,
            'position' => $i,
            'spread' => -6.5,
            'market_spread' => -6.5,
            'favorite_team_id' => $game->home_team_id,
            'odds_provider' => 'ESPN BET',
            'odds_captured_at' => '2026-09-02 09:00:00',
        ]);
    }

    $slate->update([
        'tiebreaker_slate_game_id' => $slate->games()->first()->id,
        'tiebreaker_metric' => 'combined_points',
    ]);

    return $slate->fresh();
}

it('freezes the score and its parts onto every row at publish', function () {
    [$commissioner, , $contest] = pickemContest();
    $slate = qualitySlate($contest, 3);

    GamePredictor::create(['game_id' => $slate->games()->first()->game_id, 'matchup_quality' => 70.0]);

    expect(app(PublishSlate::class)->handle($commissioner, $slate))->toBe([]);

    $rows = $slate->fresh()->games;

    expect($rows)->toHaveCount(3)
        ->and($rows->every(fn (SlateGame $row) => $row->quality !== null))->toBeTrue()
        ->and($rows->every(fn (SlateGame $row) => ($row->quality_parts['v'] ?? null) === 1))->toBeTrue();

    $scored = $rows->firstWhere('game_id', $slate->games()->first()->game_id);

    expect($scored->quality)->toBe(52.71)
        // MySQL's JSON column hands 70.0 back as 70; a re-fit casts, and
        // the fact worth pinning is that the RAW feature made the trip.
        ->and($scored->quality_parts['raw']['matchup_quality'])->toEqual(70.0);
});

it('records both columns null when the game could not be scored', function () {
    // Legitimate, not a failure: components() reads the LIVE current odd,
    // while this slate's line was frozen into `spread` days earlier.
    [$commissioner, , $contest] = pickemContest();
    $slate = qualitySlate($contest, 2);

    GameOdd::query()->delete();

    expect(app(PublishSlate::class)->handle($commissioner, $slate))->toBe([]);

    $row = $slate->fresh()->games->first();

    expect($row->quality)->toBeNull()
        ->and($row->quality_parts)->toBeNull()
        // The frozen line survives, which is what keeps tightness and
        // movement recomputable from this row later.
        ->and($row->spread)->toBe(-6.5);
});

it('never bakes a group opinion into a per-game fact', function () {
    // SuggestSlate::AFFINITY_BONUS is what a room thinks of a game. The
    // snapshot is what the game IS — the same matchup on two rooms' slates
    // must record the same number or the feature is poisoned.
    [$commissioner, , $contest] = pickemContest();
    $slate = qualitySlate($contest, 2);

    $game = $slate->games()->first()->game;
    app(FollowTeam::class)->handle($commissioner, $game->homeTeam);

    app(PublishSlate::class)->handle($commissioner, $slate);

    // 10.71 is the bare score. SuggestSlate would have ranked this game at
    // 18.71 for this room; the row records the game, not the room.
    expect($slate->fresh()->games->first()->quality)
        ->toBe(GameQualityScore::for($game->fresh()))
        ->toBe(10.71);
});

it('never rewrites the snapshot on a second press of publish', function () {
    [$commissioner, , $contest] = pickemContest();
    $slate = qualitySlate($contest, 2);

    app(PublishSlate::class)->handle($commissioner, $slate);

    $frozen = $slate->fresh()->games->first()->quality_parts;

    // The market moves on; the record of the moment of publish does not.
    GameOdd::query()->where('phase', GameOdd::CURRENT)->update(['spread' => -0.5]);

    expect(app(PublishSlate::class)->handle($commissioner, $slate->fresh()))->toBe([]);

    expect($slate->fresh()->games->first()->quality_parts)->toBe($frozen);
});

it('costs the same per row however many rows there are', function () {
    // The eager load is load-bearing and NO feature test can see it fail:
    // preventLazyLoading's per-instance flag is false under test, so a
    // missing one resolves silently here and N+1s only in production —
    // inside a transaction, holding a write lock. Query count is the only
    // instrument left. A fifteen-game slate would carry ~45 extra reads.
    $countFor = function (int $games): int {
        [$commissioner, , $contest] = pickemContest();
        $slate = qualitySlate($contest, $games);

        // Both measurements start cold. GameRanks memoizes its release maps
        // in a STATIC on top of the cache, so flushing one without the other
        // lets the second run skip three lookups the first one paid for —
        // which reads exactly like the regression this test is for.
        Cache::flush();
        GameRanks::flush();
        CfbCalendar::flush();

        /*
         * The league clock is deliberately NOT flushed with them. Publish
         * reads it once to decide practice-or-real, Cadence memoizes it
         * for the request, and the row is created on first ask — so
         * whichever measurement ran first would wear two extra queries
         * that scale with nothing. Warm it, then measure the rows.
         */
        Cadence::countsFrom();

        DB::enableQueryLog();
        DB::flushQueryLog();

        app(PublishSlate::class)->force($slate);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // Six rows cost four more UPDATEs than two rows, and nothing else.
    expect($countFor(6))->toBe($countFor(2) + 4);
});

it('names the relations the snapshot reads', function () {
    // The source sweep tests.md prescribes for the class of bug a render
    // assertion cannot reach.
    expect(file_get_contents(app_path('Actions/PublishSlate.php')))
        ->toContain("'games.game.odds'")
        ->toContain("'games.game.predictor'");
});
