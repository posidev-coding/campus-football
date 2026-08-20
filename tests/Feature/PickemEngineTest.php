<?php

use App\Enums\ContestMode;
use App\Models\Contest;
use App\Models\Game;
use App\Models\GameOdd;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\Week;
use App\Services\Contests\GameQualityScore;
use App\Services\Contests\SpreadGrader;
use App\Services\Contests\SuggestSlate;
use Illuminate\Support\Facades\Http;

/*
 * Phase 5 slice 2: the contest engine. Everything here is pure over rows we
 * already hold — the suite fakes HTTP and asserts nothing was ever sent,
 * because the engine adding an ESPN request would violate the sync budget
 * by design rather than by accident.
 */

// ------------------------------- fixtures (shared ones in PickemFixtures)

/** A complete, publishable board for the mode, tiers filled per its spec. */
function pickemBoard(ContestMode $mode): Slate
{
    [$season, $week] = pickemSeasonWeek();
    $contest = Contest::factory()->create(['mode' => $mode]);
    $slate = Slate::factory()->create(['contest_id' => $contest->id, 'week_id' => $week->id]);

    $engine = $mode->engine();
    $tiers = [];

    foreach ($engine->tierSpec() ?? [] as $tier => $count) {
        $tiers = [...$tiers, ...array_fill(0, $count, $tier)];
    }

    foreach (range(1, $engine->slateSize()) as $i) {
        $game = pickemGame($season, $week);

        SlateGame::factory()->create([
            'slate_id' => $slate->id,
            'game_id' => $game->id,
            'tier' => $tiers[$i - 1] ?? null,
            'position' => $i,
            'spread' => -6.5,
            'favorite_team_id' => $game->home_team_id,
            'odds_provider' => 'ESPN BET',
            'odds_captured_at' => '2026-09-02 18:00:00',
        ]);
    }

    $slate->update([
        'tiebreaker_slate_game_id' => $slate->games()->first()->id,
        'tiebreaker_metric' => 'combined_points',
    ]);

    return $slate->fresh();
}

/** An unsaved game+line pair for the pure grader tests — no database. */
function pickemGraded(int $homeScore, int $awayScore, float $spread = -6.5, ?int $favorite = 10): array
{
    $game = (new Game)->forceFill([
        'id' => 1, 'home_team_id' => 10, 'away_team_id' => 20,
        'home_score' => $homeScore, 'away_score' => $awayScore, 'completed' => true,
    ]);
    $slateGame = (new SlateGame)->forceFill([
        'id' => 1, 'spread' => $spread, 'favorite_team_id' => $favorite,
    ]);

    return [$slateGame, $game];
}

// ------------------------------------------------------------ SpreadGrader

it('grades a favorite that covers, and the dog that did not', function () {
    [$slateGame, $game] = pickemGraded(homeScore: 31, awayScore: 24); // home favored by 6.5, wins by 7

    $grader = new SpreadGrader;

    expect($grader->resultFor($slateGame, $game, 10))->toBe(Pick::WIN)
        ->and($grader->resultFor($slateGame, $game, 20))->toBe(Pick::LOSS);
});

it('grades a favorite that wins the game but not the number', function () {
    [$slateGame, $game] = pickemGraded(homeScore: 30, awayScore: 24); // wins by 6, needed 6.5

    $grader = new SpreadGrader;

    expect($grader->resultFor($slateGame, $game, 10))->toBe(Pick::LOSS)
        ->and($grader->resultFor($slateGame, $game, 20))->toBe(Pick::WIN);
});

it('grades landing exactly on a whole-number spread as a push for both sides', function () {
    [$slateGame, $game] = pickemGraded(homeScore: 31, awayScore: 24, spread: -7.0);

    $grader = new SpreadGrader;

    expect($grader->resultFor($slateGame, $game, 10))->toBe(Pick::PUSH)
        ->and($grader->resultFor($slateGame, $game, 20))->toBe(Pick::PUSH);
});

it('grades a dog winning outright as covering', function () {
    [$slateGame, $game] = pickemGraded(homeScore: 17, awayScore: 21);

    expect((new SpreadGrader)->resultFor($slateGame, $game, 20))->toBe(Pick::WIN);
});

it('grades an away favorite by the same burden', function () {
    // Away team (20) favored by 6.5, wins by 10 on the road: covers.
    [$slateGame, $game] = pickemGraded(homeScore: 14, awayScore: 24, favorite: 20);

    expect((new SpreadGrader)->resultFor($slateGame, $game, 20))->toBe(Pick::WIN);
});

it('grades a positively-signed spread identically — the sign is convention, the magnitude is the burden', function () {
    [$slateGame, $game] = pickemGraded(homeScore: 31, awayScore: 24, spread: 6.5);

    expect((new SpreadGrader)->resultFor($slateGame, $game, 10))->toBe(Pick::WIN);
});

it('refuses to grade corrupt state instead of guessing', function () {
    $grader = new SpreadGrader;

    [$noLine, $game] = pickemGraded(31, 24);
    $noLine->spread = null;
    expect(fn () => $grader->resultFor($noLine, $game, 10))->toThrow(InvalidArgumentException::class);

    [$slateGame, $unfinished] = pickemGraded(31, 24);
    $unfinished->completed = false;
    expect(fn () => $grader->resultFor($slateGame, $unfinished, 10))->toThrow(InvalidArgumentException::class);

    [$slateGame, $game] = pickemGraded(31, 24);
    expect(fn () => $grader->resultFor($slateGame, $game, 999))->toThrow(InvalidArgumentException::class);
});

// ------------------------------------------------------------- mode engines

it('defines Shotgun as ten ten-point games and Triple Option as fifteen in tiers of 9, 7 and 4', function () {
    $shotgun = ContestMode::Classic->engine();
    $tiered = ContestMode::Tiered->engine();

    $tierOf = fn (int $tier) => (new SlateGame)->forceFill(['tier' => $tier]);

    // The parity target: a perfect week in either mode is exactly 100.
    expect($shotgun->slateSize())->toBe(10)
        ->and($shotgun->tierSpec())->toBeNull()
        ->and($shotgun->pointsFor((new SlateGame)->forceFill(['tier' => null])))->toBe(10)
        ->and($tiered->slateSize())->toBe(15)
        ->and($tiered->tierSpec())->toBe([1 => 5, 2 => 5, 3 => 5])
        ->and($tiered->pointsFor($tierOf(1)))->toBe(9)
        ->and($tiered->pointsFor($tierOf(2)))->toBe(7)
        ->and($tiered->pointsFor($tierOf(3)))->toBe(4);
});

it('fields all three modes now that the Woodshed is configured', function () {
    expect(ContestMode::Woodshed->available())->toBeTrue()
        ->and(ContestMode::Classic->available())->toBeTrue()
        ->and(ContestMode::Tiered->available())->toBeTrue()
        ->and(ContestMode::Woodshed->engine()->slateSize())->toBe(15)
        ->and(ContestMode::Woodshed->engine()->tierSpec())->toBe([1 => 5, 2 => 5, 3 => 5]);
});

// ----------------------------------------------------- publish validation

it('declares a complete board publishable', function () {
    $this->travelTo('2026-09-02 12:00:00');

    expect(ContestMode::Classic->engine()->validateForPublish(pickemBoard(ContestMode::Classic)))->toBe([])
        ->and(ContestMode::Tiered->engine()->validateForPublish(pickemBoard(ContestMode::Tiered)))->toBe([]);
});

it('names every way a board is not ready', function () {
    $this->travelTo('2026-09-02 12:00:00');
    $engine = ContestMode::Classic->engine();

    // One game short.
    $slate = pickemBoard(ContestMode::Classic);
    $slate->update(['tiebreaker_slate_game_id' => null]);
    $slate->games()->orderByDesc('position')->first()->delete();
    $slate->update(['tiebreaker_slate_game_id' => $slate->games()->first()->id]);
    expect($engine->validateForPublish($slate->fresh()))->toContain('picks.publish.count');

    // A game whose line never arrived.
    $slate = pickemBoard(ContestMode::Classic);
    $slate->games()->first()->update(['spread' => null, 'favorite_team_id' => null]);
    expect($engine->validateForPublish($slate->fresh()))->toContain('picks.publish.line_missing');

    // A weeknight game on a Saturday board.
    $slate = pickemBoard(ContestMode::Classic);
    $slate->games()->first()->game->update(['kickoff_day' => 'Fri']);
    expect($engine->validateForPublish($slate->fresh()))->toContain('picks.publish.not_saturday');

    /*
     * A game from some other SATURDAY. This used to compare week ids, which
     * a split ESPN week satisfies twice over — 2026's Week 1 holds both 8/29
     * and 9/5 — so a board spanning a fortnight passed every check. The
     * stray here stays in the same week on purpose: only the Saturday moves,
     * which is exactly the case the week comparison could not see.
     */
    $slate = pickemBoard(ContestMode::Classic);
    $slate->games()->first()->game->update(['kickoff_at' => '2026-09-12 19:30:00']);
    expect($engine->validateForPublish($slate->fresh()))->toContain('picks.publish.wrong_saturday');

    // No tiebreaker designated.
    $slate = pickemBoard(ContestMode::Classic);
    $slate->update(['tiebreaker_slate_game_id' => null]);
    expect($engine->validateForPublish($slate->fresh()))->toContain('picks.publish.tiebreaker');

    // A tier on an untiered board.
    $slate = pickemBoard(ContestMode::Classic);
    $slate->games()->first()->update(['tier' => 1]);
    expect($engine->validateForPublish($slate->fresh()))->toContain('picks.publish.tiers');
});

it('refuses a board once its games have started', function () {
    $this->travelTo('2026-09-02 12:00:00');
    $slate = pickemBoard(ContestMode::Classic);

    $this->travelTo('2026-09-05 20:00:00');

    expect(ContestMode::Classic->engine()->validateForPublish($slate))->toContain('picks.publish.started');
});

it('holds Triple Option to five games in each tier', function () {
    $this->travelTo('2026-09-02 12:00:00');
    $slate = pickemBoard(ContestMode::Tiered);

    $slate->games()->where('tier', 3)->orderByDesc('position')->first()->update(['tier' => 2]);

    expect(ContestMode::Tiered->engine()->validateForPublish($slate->fresh()))->toContain('picks.publish.tiers');
});

// -------------------------------------------------------- GameQualityScore

it('scores no game that has no usable line', function () {
    [$season, $week] = pickemSeasonWeek();
    $game = pickemGame($season, $week);

    expect(GameQualityScore::for($game))->toBeNull();
});

it('ranks a better matchup above a worse one', function () {
    [$season, $week] = pickemSeasonWeek();

    $better = pickemGame($season, $week);
    $worse = pickemGame($season, $week);
    pickemOdd($better);
    pickemOdd($worse);
    $better->predictor()->create(['matchup_quality' => 85.0]);
    $worse->predictor()->create(['matchup_quality' => 40.0]);

    expect(GameQualityScore::for($better))->toBeGreaterThan(GameQualityScore::for($worse));
});

it('prefers a tight line to a blowout line', function () {
    [$season, $week] = pickemSeasonWeek();

    $tight = pickemGame($season, $week);
    $blowout = pickemGame($season, $week);
    pickemOdd($tight, ['spread' => -2.5]);
    pickemOdd($blowout, ['spread' => -24.5]);

    expect(GameQualityScore::for($tight))->toBeGreaterThan(GameQualityScore::for($blowout));
});

it('credits line movement only where an open exists to move from', function () {
    [$season, $week] = pickemSeasonWeek();

    $moved = pickemGame($season, $week);
    $still = pickemGame($season, $week);
    pickemOdd($moved);
    pickemOdd($moved, ['phase' => GameOdd::OPEN, 'spread' => -2.5]);
    pickemOdd($still);

    expect(GameQualityScore::for($moved))->toBeGreaterThan(GameQualityScore::for($still));
});

it('credits a conference game', function () {
    [$season, $week] = pickemSeasonWeek();

    $conference = pickemGame($season, $week, ['conference_game' => true]);
    $nonConference = pickemGame($season, $week);
    pickemOdd($conference);
    pickemOdd($nonConference);

    expect(GameQualityScore::for($conference))->toBe(GameQualityScore::for($nonConference) + 5.0);
});

// ------------------------------------------------------------ SuggestSlate

it('suggests the ten best lined games for Classic and never a spreadless one', function () {
    Http::fake();
    $this->travelTo('2026-09-02 12:00:00');

    [$season, $week] = pickemSeasonWeek();
    $contest = Contest::factory()->create();

    $games = collect(range(0, 11))->map(function (int $i) use ($season, $week) {
        $game = pickemGame($season, $week);
        pickemOdd($game);
        $game->predictor()->create(['matchup_quality' => 90 - $i * 5]);

        return $game;
    });

    // The best matchup of the week has no line: it cannot be suggested.
    $spreadless = pickemGame($season, $week);
    $spreadless->predictor()->create(['matchup_quality' => 99.0]);

    $board = (new SuggestSlate)->for($contest, $week);

    expect($board)->toHaveCount(10)
        ->and(collect($board)->pluck('game_id'))->not->toContain($spreadless->id)
        ->and($board[0]['game_id'])->toBe($games[0]->id)
        ->and(collect($board)->pluck('game_id'))->not->toContain($games[10]->id, $games[11]->id)
        ->and($board[0]['tier'])->toBeNull()
        ->and($board[0]['spread'])->toBe(-6.5);

    Http::assertNothingSent();
});

it('banded Triple Option suggestions put the best five in tier 1', function () {
    Http::fake();
    $this->travelTo('2026-09-02 12:00:00');

    [$season, $week] = pickemSeasonWeek();
    $contest = Contest::factory()->tiered()->create();

    $games = collect(range(0, 14))->map(function (int $i) use ($season, $week) {
        $game = pickemGame($season, $week);
        pickemOdd($game);
        $game->predictor()->create(['matchup_quality' => 95 - $i * 3]);

        return $game;
    });

    $board = collect((new SuggestSlate)->for($contest, $week));

    expect($board)->toHaveCount(15)
        ->and($board->countBy('tier')->all())->toBe([1 => 5, 2 => 5, 3 => 5])
        ->and($board->where('tier', 1)->pluck('game_id')->all())
        ->toBe($games->take(5)->pluck('id')->all());

    Http::assertNothingSent();
});

it("boosts a game the group's people follow past an otherwise equal one", function () {
    $this->travelTo('2026-09-02 12:00:00');

    [$season, $week] = pickemSeasonWeek();
    $membership = GroupMember::factory()->create();
    $contest = Contest::factory()->create(['group_id' => $membership->group_id]);

    $plain = pickemGame($season, $week);
    $followed = pickemGame($season, $week);
    pickemOdd($plain);
    pickemOdd($followed);

    $membership->user->followedTeams()->attach($followed->home_team_id, ['position' => 1]);

    $board = (new SuggestSlate)->for($contest, $week);

    expect($board[0]['game_id'])->toBe($followed->id)
        ->and($board[0]['score'])->toBe($board[1]['score'] + 8.0);
});

it('suggests a banded Woodshed board now that its rules landed', function () {
    Http::fake();
    $this->travelTo('2026-09-02 12:00:00');

    [$season, $week] = pickemSeasonWeek();
    $contest = Contest::factory()->woodshed()->create();

    collect(range(0, 14))->each(function (int $i) use ($season, $week) {
        $game = pickemGame($season, $week);
        pickemOdd($game);
        $game->predictor()->create(['matchup_quality' => 95 - $i * 3]);
    });

    $board = collect((new SuggestSlate)->for($contest, $week));

    expect($board)->toHaveCount(15)
        ->and($board->countBy('tier')->all())->toBe([1 => 5, 2 => 5, 3 => 5]);

    Http::assertNothingSent();
});
