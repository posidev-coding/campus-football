<?php

use App\Enums\ContestMode;
use App\Models\Contest;
use App\Models\Game;
use App\Models\GameOdd;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Season;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\User;
use App\Models\Week;

/*
 * Shared pick'em fixtures, loaded from Pest.php so every Pickem*Test file
 * draws the same graph the same way. Plain global functions — defined ONCE
 * here; a test file redefining one is a fatal, which is the point.
 */

/** An admin — inside the `pickem` flag while the phase builds out. */
function pickemAdmin(): User
{
    return User::factory()->create(['admin' => true]);
}

/**
 * One pinned 2026 regular season and its week 1 — REUSED within a test,
 * because seasons carry a (year, type) unique and a test building several
 * boards would otherwise collide with itself.
 */
function pickemSeasonWeek(): array
{
    $season = Season::query()->where(['year' => 2026, 'type' => Season::REGULAR])->first()
        ?? Season::factory()->create(['year' => 2026, 'type' => Season::REGULAR]);
    $week = Week::query()->where(['season_id' => $season->id, 'number' => 1])->first()
        ?? Week::factory()->create(['season_id' => $season->id]);

    return [$season, $week];
}

/** A slate-eligible game: pinned SATURDAY kickoff inside the given week. */
function pickemGame(Season $season, Week $week, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'season_id' => $season->id,
        'week_id' => $week->id,
        'kickoff_at' => '2026-09-05 19:30:00',
    ], $overrides));
}

/** A current-phase line the suggestion engine can freeze. */
function pickemOdd(Game $game, array $overrides = []): GameOdd
{
    return GameOdd::create(array_merge([
        'game_id' => $game->id,
        'provider_id' => 58,
        'provider' => 'ESPN BET',
        'phase' => GameOdd::CURRENT,
        'spread' => -6.5,
        'favorite_team_id' => $game->home_team_id,
        'captured_at' => '2026-09-02 09:00:00',
    ], $overrides));
}

/**
 * The real shape of ESPN's 2026 opening week: ONE week row spanning
 * 8/22 → 9/8, games on TWO Saturdays (seven on 8/29, twelve on 9/5), and
 * nothing at all on the 8/22 the range opens with. Retunes the shared
 * season-week pair rather than minting its own, so it composes with every
 * other fixture inside a test.
 *
 * @return array{0: Season, 1: Week}
 */
function splitPickemWeek(): array
{
    [$season, $week] = pickemSeasonWeek();

    $week->update(['start_date' => '2026-08-22 04:00:00', 'end_date' => '2026-09-08 03:59:59']);

    foreach (range(1, 7) as $i) {
        pickemGame($season, $week, ['kickoff_at' => '2026-08-29 20:00:00']);
    }

    foreach (range(1, 12) as $i) {
        pickemGame($season, $week, ['kickoff_at' => '2026-09-05 19:30:00']);
    }

    return [$season, $week->fresh()];
}

/**
 * A contest inside a real group with a commissioner — the graph every
 * builder and publish test stands on.
 *
 * @return array{0: User, 1: Group, 2: Contest}
 */
function pickemContest(ContestMode $mode = ContestMode::Classic): array
{
    $commissioner = pickemAdmin();
    $group = Group::factory()->create();
    GroupMember::factory()->commissioner()->create([
        'group_id' => $group->id, 'user_id' => $commissioner->id,
    ]);
    $contest = Contest::factory()->create(['group_id' => $group->id, 'mode' => $mode]);

    return [$commissioner, $group, $contest];
}

/**
 * A complete, publishable DRAFT board for the mode: full count, tiers per
 * spec, tiebreaker designated, and every row SEEDED the way AddSlateGame
 * seeds it — the half-pointed contest line, the book's number beside it,
 * and the provenance of both. Publish validates and commits; it copies
 * nothing.
 */
function pickemDraftBoard(Contest $contest): Slate
{
    [$season, $week] = pickemSeasonWeek();
    $slate = Slate::factory()->create(['contest_id' => $contest->id, 'week_id' => $week->id]);

    $engine = $contest->mode->engine();
    $tiers = [];

    foreach ($engine->tierSpec() ?? [] as $tier => $count) {
        $tiers = [...$tiers, ...array_fill(0, $count, $tier)];
    }

    foreach (range(1, $engine->slateSize()) as $i) {
        $game = pickemGame($season, $week);
        pickemOdd($game);

        SlateGame::factory()->create([
            'slate_id' => $slate->id,
            'game_id' => $game->id,
            'tier' => $tiers[$i - 1] ?? null,
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
