<?php

use App\Actions\AddSlateGame;
use App\Actions\PublishSlate;
use App\Actions\RemoveSlateGame;
use App\Actions\SetSlateGameLine;
use App\Actions\SetSlateGameTier;
use App\Actions\SetTiebreaker;
use App\Enums\ContestMode;
use App\Enums\TiebreakerMetric;
use App\Exceptions\NotGroupCommissioner;
use App\Models\GameOdd;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\User;
use App\Services\Espn\Sync\SyncOdds;
use Livewire\Livewire;

/*
 * Phase 5 slice 4: building and publishing a board. The one fact this file
 * exists to hold: PUBLISH FREEZES THE LINE — the market moving after
 * publish must never move the board.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

// ---------------------------------------------------------------- publish

it('publishes a full board and freezes each line with its provenance', function () {
    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftBoard($contest);

    $problems = app(PublishSlate::class)->handle($commissioner, $slate);
    $slate->refresh();

    expect($problems)->toBe([])
        ->and($slate->status)->toBe(Slate::PUBLISHED)
        ->and($slate->published_at)->not->toBeNull();

    $frozen = $slate->games()->first();
    expect((float) $frozen->spread)->toBe(-6.5)
        ->and($frozen->favorite_team_id)->toBe($frozen->game->home_team_id)
        ->and($frozen->odds_provider)->toBe('ESPN BET')
        ->and($frozen->odds_captured_at->toDateTimeString())->toBe('2026-09-02 09:00:00');
});

it('keeps a published board still while the market moves on', function () {
    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftBoard($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    $slateGame = $slate->games()->first();
    $game = $slateGame->game;

    // The sync rewrites the CURRENT phase in place — the exact mechanism
    // that makes copying at publish the only correct freeze.
    app(SyncOdds::class)->fromCompetition($game->id, ['odds' => [[
        'provider' => ['id' => 58, 'name' => 'ESPN BET'],
        'spread' => -12.5,
        'overUnder' => 51.5,
        'homeTeamOdds' => ['favorite' => true, 'team' => ['id' => $game->home_team_id]],
        'details' => 'HOME -12.5',
    ]]]);

    $current = GameOdd::query()
        ->where(['game_id' => $game->id, 'phase' => GameOdd::CURRENT])
        ->first();

    expect((float) $current->spread)->toBe(-12.5)
        ->and((float) $slateGame->fresh()->spread)->toBe(-6.5);
});

it('re-publishing is a quiet no-op that never re-freezes newer lines', function () {
    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftBoard($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);

    $game = $slate->games()->first()->game;
    GameOdd::query()->where(['game_id' => $game->id, 'phase' => GameOdd::CURRENT])
        ->update(['spread' => -20.0]);

    expect(app(PublishSlate::class)->handle($commissioner, $slate->fresh()))->toBe([])
        ->and((float) $slate->games()->first()->fresh()->spread)->toBe(-6.5);
});

it('names each violation and stays a draft', function () {
    [$commissioner, , $contest] = pickemContest();

    // One short: delete a game (repoint the tiebreaker first).
    $slate = pickemDraftBoard($contest);
    $slate->update(['tiebreaker_slate_game_id' => $slate->games()->first()->id]);
    $slate->games()->orderByDesc('position')->first()->delete();
    $problems = app(PublishSlate::class)->handle($commissioner, $slate->fresh());
    expect($problems)->toContain('picks.publish.count')
        ->and($slate->fresh()->status)->toBe(Slate::DRAFT);

    // A game whose line never posted was never seeded: refused.
    [$c2, , $contest2] = pickemContest();
    $slate2 = pickemDraftBoard($contest2);
    $slate2->games()->first()->update(['spread' => null, 'favorite_team_id' => null]);
    expect(app(PublishSlate::class)->handle($c2, $slate2->fresh()))
        ->toContain('picks.publish.line_missing');

    // THE HALF-POINT LAW: a whole-number line cannot publish.
    [$c4, , $contest4] = pickemContest();
    $slate4 = pickemDraftBoard($contest4);
    $slate4->games()->first()->update(['spread' => -7.0]);
    expect(app(PublishSlate::class)->handle($c4, $slate4->fresh()))
        ->toContain('picks.publish.whole_line');

    // No tiebreaker designated.
    [$c3, , $contest3] = pickemContest();
    $slate3 = pickemDraftBoard($contest3);
    $slate3->update(['tiebreaker_slate_game_id' => null]);
    expect(app(PublishSlate::class)->handle($c3, $slate3->fresh()))
        ->toContain('picks.publish.tiebreaker');
});

it('holds a Triple Option board to its tier spec at publish', function () {
    [$commissioner, , $contest] = pickemContest(ContestMode::Tiered);
    $slate = pickemDraftBoard($contest);
    $slate->games()->where('tier', 3)->orderByDesc('position')->first()->update(['tier' => 1]);

    expect(app(PublishSlate::class)->handle($commissioner, $slate->fresh()))
        ->toContain('picks.publish.tiers');
});

// -------------------------------------------------------- half-point law

it('seeds a half-pointed contest line from a whole-number book', function () {
    [$commissioner, , $contest] = pickemContest();
    [$season, $week] = pickemSeasonWeek();
    $slate = Slate::factory()->create(['contest_id' => $contest->id, 'week_id' => $week->id]);

    // Book says home by exactly 7: the contest line seeds at 6.5.
    $whole = pickemGame($season, $week);
    pickemOdd($whole, ['spread' => -7.0]);
    $seeded = app(AddSlateGame::class)->handle($commissioner, $slate, $whole);
    expect((float) $seeded->spread)->toBe(-6.5)
        ->and((float) $seeded->market_spread)->toBe(-7.0)
        ->and($seeded->favorite_team_id)->toBe($whole->home_team_id);

    // An away favorite keeps the positive home-relative sign.
    $road = pickemGame($season, $week);
    pickemOdd($road, ['spread' => 3.0, 'favorite_team_id' => $road->away_team_id]);
    $seededRoad = app(AddSlateGame::class)->handle($commissioner, $slate, $road);
    expect((float) $seededRoad->spread)->toBe(2.5)
        ->and($seededRoad->favorite_team_id)->toBe($road->away_team_id);

    // No book, no seed — the row stays honestly empty.
    $pending = pickemGame($season, $week);
    $seededPending = app(AddSlateGame::class)->handle($commissioner, $slate, $pending);
    expect($seededPending->spread)->toBeNull()
        ->and($seededPending->market_spread)->toBeNull();
});

it('lets the commissioner move a line up to three off the book, on half points only', function () {
    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftBoard($contest);
    $slateGame = $slate->games()->with('game')->first();

    // 6.5 → 7.5 within the band of a -6.5 book: legal.
    app(SetSlateGameLine::class)->handle($commissioner, $slate, $slateGame, 7.5);
    expect((float) $slateGame->fresh()->spread)->toBe(-7.5);

    // The band's ceiling is book + 3.0.
    app(SetSlateGameLine::class)->handle($commissioner, $slate, $slateGame->fresh(), 9.5);
    expect(fn () => app(SetSlateGameLine::class)->handle($commissioner, $slate, $slateGame->fresh(), 10.0))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(SetSlateGameLine::class)->handle($commissioner, $slate, $slateGame->fresh(), 10.5))
        ->toThrow(InvalidArgumentException::class);

    // Whole numbers never pass, even inside the band.
    expect(fn () => app(SetSlateGameLine::class)->handle($commissioner, $slate, $slateGame->fresh(), 7.0))
        ->toThrow(InvalidArgumentException::class);

    // A published board's lines are committed.
    app(PublishSlate::class)->handle($commissioner, $slate->fresh());
    expect(fn () => app(SetSlateGameLine::class)->handle($commissioner, $slate->fresh(), $slateGame->fresh(), 6.5))
        ->toThrow(InvalidArgumentException::class);
});

it('never lets an adjustment go below half a point or flip the favorite', function () {
    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftBoard($contest);
    $slateGame = $slate->games()->with('game')->first();

    // Book -6.5: the floor is 3.5 (band), never zero, never the other side.
    app(SetSlateGameLine::class)->handle($commissioner, $slate, $slateGame, 3.5);
    $fresh = $slateGame->fresh();
    expect((float) $fresh->spread)->toBe(-3.5)
        ->and($fresh->favorite_team_id)->toBe($slateGame->game->home_team_id);

    expect(fn () => app(SetSlateGameLine::class)->handle($commissioner, $slate, $slateGame->fresh(), 3.0))
        ->toThrow(InvalidArgumentException::class);
});

it('asks a different question each week: metric and team on the tiebreaker', function () {
    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftBoard($contest);
    $tiebreakerGame = $slate->games()->with('game')->first();

    // A one-sided question stores its team; a stranger is refused.
    app(SetTiebreaker::class)->handle($commissioner, $slate, $tiebreakerGame, TiebreakerMetric::PassingYards, $tiebreakerGame->game->away_team_id);
    $fresh = $slate->fresh();
    expect($fresh->tiebreaker_metric)->toBe(TiebreakerMetric::PassingYards)
        ->and($fresh->tiebreaker_team_id)->toBe($tiebreakerGame->game->away_team_id);

    expect(fn () => app(SetTiebreaker::class)->handle($commissioner, $slate->fresh(), $tiebreakerGame, TiebreakerMetric::TeamPoints, 999999))
        ->toThrow(InvalidArgumentException::class);

    // Back to a whole-game question: the stale team never lingers.
    app(SetTiebreaker::class)->handle($commissioner, $slate->fresh(), $tiebreakerGame, TiebreakerMetric::CombinedPoints);
    expect($slate->fresh()->tiebreaker_team_id)->toBeNull();

    // And the question phrases itself for the sheet.
    expect(TiebreakerMetric::PassingYards->question($tiebreakerGame, $tiebreakerGame->game->awayTeam()->first()))
        ->toContain('Passing yards — ');
});

it('refuses to publish a half-designated tiebreaker question', function () {
    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftBoard($contest);

    $slate->update(['tiebreaker_metric' => null]);
    expect(app(PublishSlate::class)->handle($commissioner, $slate->fresh()))
        ->toContain('picks.publish.tiebreaker');

    // A one-sided metric with nobody's name on it is half a question.
    $slate->update(['tiebreaker_metric' => 'passing_yards', 'tiebreaker_team_id' => null]);
    expect(app(PublishSlate::class)->handle($commissioner, $slate->fresh()))
        ->toContain('picks.publish.tiebreaker');
});

it('lets only the commissioner publish', function () {
    [, $group, $contest] = pickemContest();
    $member = User::factory()->create();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);
    $slate = pickemDraftBoard($contest);

    expect(fn () => app(PublishSlate::class)->handle($member, $slate))
        ->toThrow(NotGroupCommissioner::class);
});

// ------------------------------------------------------------ draft edits

it('adds only eligible games, idempotently', function () {
    [$commissioner, , $contest] = pickemContest();
    [$season, $week] = pickemSeasonWeek();
    $slate = Slate::factory()->create(['contest_id' => $contest->id, 'week_id' => $week->id]);

    $game = pickemGame($season, $week);
    app(AddSlateGame::class)->handle($commissioner, $slate, $game);
    app(AddSlateGame::class)->handle($commissioner, $slate, $game);
    expect($slate->games()->count())->toBe(1);

    $friday = pickemGame($season, $week, ['kickoff_at' => '2026-09-04 19:00:00']);
    expect(fn () => app(AddSlateGame::class)->handle($commissioner, $slate, $friday))
        ->toThrow(InvalidArgumentException::class);
});

it('clears the tiebreaker when its game is removed', function () {
    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftBoard($contest);
    $tiebreaker = $slate->games()->find($slate->tiebreaker_slate_game_id);

    app(RemoveSlateGame::class)->handle($commissioner, $slate, $tiebreaker);

    expect($slate->fresh()->tiebreaker_slate_game_id)->toBeNull();
});

it('refuses edits to a published board and to foreign slate games', function () {
    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftBoard($contest);
    $foreign = pickemDraftBoard(pickemContest(ContestMode::Tiered)[2])->games()->first();

    expect(fn () => app(SetTiebreaker::class)->handle($commissioner, $slate, $foreign))
        ->toThrow(InvalidArgumentException::class);

    app(PublishSlate::class)->handle($commissioner, $slate);
    $slateGame = $slate->fresh()->games()->first();

    expect(fn () => app(SetSlateGameTier::class)->handle($commissioner, $slate->fresh(), $slateGame, 2))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------- screens

it('opens the wizard pre-filled from suggestions for the commissioner', function () {
    [$commissioner, $group] = pickemContest();
    [$season, $week] = pickemSeasonWeek();

    foreach (range(1, 12) as $i) {
        $game = pickemGame($season, $week);
        pickemOdd($game);
        $game->predictor()->create(['matchup_quality' => 90 - $i]);
    }

    Livewire::actingAs($commissioner)->test('slate-builder', ['group' => $group])
        ->assertSee('Shotgun slate')
        ->assertSee('10 of 10')
        ->set('step', 'preview')
        ->assertSee('Publish the slate');
});

it('publishes from the wizard and lands back on the clubhouse', function () {
    [$commissioner, $group, $contest] = pickemContest();
    $slate = pickemDraftBoard($contest);

    Livewire::actingAs($commissioner)->test('slate-builder', ['group' => $group])
        ->call('publish')
        ->assertRedirect(route('pickem.group', $group));

    expect($slate->fresh()->status)->toBe(Slate::PUBLISHED);
});

it('keeps non-commissioners out of the wizard, and walks the old URL to it', function () {
    [, $group, $contest] = pickemContest();
    $member = User::factory()->create(['admin' => true]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    $this->actingAs($member)->get(route('pickem.build', $group))->assertForbidden();

    // The old per-contest address hops to the group's wizard — the gate
    // waits on the other side.
    $this->actingAs($member)->get(route('picks.build', $contest))
        ->assertRedirect(route('pickem.build', $group));
});

it('shows the published slate to everyone and the build prompt only to the commissioner', function () {
    // Fixtures FIRST: the calendar's week list caches per year and only
    // counts weeks that hold games, so a render before any games exist
    // would prime an empty cache this test then starves on.
    [$commissioner, $group, $contest] = pickemContest();
    app(PublishSlate::class)->handle($commissioner, pickemDraftBoard($contest));

    // The frozen contest line rides every card, for every seat in the room.
    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSee('-6.5');

    $member = pickemAdmin();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    // The draft-week build prompt is GroupPageTest's ground; here it only
    // matters that a member never sees the commissioner's door.
    Livewire::actingAs($member)->test('group', ['group' => $group])
        ->assertSee('-6.5')
        ->assertDontSee('Build the slate');
});
