<?php

use App\Enums\ContestMode;
use App\Models\Article;
use App\Models\Contest;
use App\Models\ConversationPost;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Models\Team;
use App\Models\User;
use App\Models\Week;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\QueryException;

/*
 * Phase 5 slice 1: the pick'em schema and models hold the shape the rest of
 * the phase stands on. What is asserted here is exactly what later slices
 * assume — the unique indexes that make actions idempotent, the null
 * semantics that carry the no-defaults rule, and the enforced morph map
 * that keeps The Conversation to its three scopes.
 */

it('wires the whole contest graph together', function () {
    $commissioner = User::factory()->create();
    $group = Group::factory()->create(['name' => 'The Test Group']);
    GroupMember::factory()->commissioner()->create([
        'group_id' => $group->id, 'user_id' => $commissioner->id,
    ]);

    $contest = Contest::factory()->tiered()->create(['group_id' => $group->id]);
    $slate = Slate::factory()->create(['contest_id' => $contest->id]);
    $slateGame = SlateGame::factory()->frozen()->create(['slate_id' => $slate->id, 'tier' => 1]);
    $pick = Pick::factory()->create([
        'slate_game_id' => $slateGame->id, 'user_id' => $commissioner->id,
    ]);
    SlateEntry::factory()->create(['slate_id' => $slate->id, 'user_id' => $commissioner->id]);

    expect($group->members()->first()->id)->toBe($commissioner->id)
        ->and($group->members()->first()->pivot->role)->toBe(GroupMember::COMMISSIONER)
        ->and($commissioner->groups()->first()->id)->toBe($group->id)
        ->and($group->contests()->first()->mode)->toBe(ContestMode::Tiered)
        ->and($contest->slates()->first()->id)->toBe($slate->id)
        ->and($slate->games()->first()->id)->toBe($slateGame->id)
        ->and($slateGame->picks()->first()->id)->toBe($pick->id)
        ->and($slate->entries()->count())->toBe(1);
});

it('refuses a second pick on the same slate game by the same user', function () {
    $pick = Pick::factory()->create();

    expect(fn () => Pick::factory()->create([
        'slate_game_id' => $pick->slate_game_id,
        'user_id' => $pick->user_id,
    ]))->toThrow(QueryException::class);
});

it('refuses a second contest for the same group, season and mode', function () {
    $contest = Contest::factory()->create();

    expect(fn () => Contest::factory()->create([
        'group_id' => $contest->group_id,
        'season_year' => $contest->season_year,
        'mode' => $contest->mode,
    ]))->toThrow(QueryException::class);
});

it('refuses a second slate for the same contest and week', function () {
    $slate = Slate::factory()->create();

    expect(fn () => Slate::factory()->create([
        'contest_id' => $slate->contest_id,
        'week_id' => $slate->week_id,
    ]))->toThrow(QueryException::class);
});

it('keeps null settings null — mode defaults, not an empty object', function () {
    $contest = Contest::factory()->create();

    expect($contest->fresh()->settings)->toBeNull();
});

it('carries the product names on the mode enum, not in data', function () {
    // The stored values stay neutral; a rename never touches a row — and
    // the Shotgun rename (2026-08-14) proved it: label changed, value held.
    expect(ContestMode::Classic->label())->toBe('Shotgun')
        ->and(ContestMode::Classic->value)->toBe('classic')
        ->and(ContestMode::Tiered->label())->toBe('Triple Option')
        ->and(ContestMode::Woodshed->label())->toBe('The Woodshed')
        ->and(ContestMode::Tiered->value)->toBe('tiered');
});

it('distinguishes an ungraded pick from one graded to nothing', function () {
    // One slate game shared by both picks — two users, no unique clash,
    // and the fixture graph stays as small as the assertion needs.
    $slateGame = SlateGame::factory()->create();
    $ungraded = Pick::factory()->create(['slate_game_id' => $slateGame->id]);
    $pushed = Pick::factory()->pushed()->create(['slate_game_id' => $slateGame->id]);

    expect($ungraded->result)->toBeNull()
        ->and($ungraded->points)->toBeNull()
        ->and($pushed->result)->toBe(Pick::PUSH)
        ->and($pushed->points)->toBe(0);
});

it('resolves conversation topics through the enforced morph map', function () {
    $game = Game::factory()->create(['home_team_id' => null, 'away_team_id' => null]);
    $team = Team::factory()->create();
    $group = Group::factory()->create();

    $onGame = ConversationPost::factory()->create(['topic_type' => 'game', 'topic_id' => $game->id]);
    $onTeam = ConversationPost::factory()->create(['topic_type' => 'team', 'topic_id' => $team->id]);
    $onGroup = ConversationPost::factory()->create(['topic_type' => 'group', 'topic_id' => $group->id]);

    expect($onGame->topic)->toBeInstanceOf(Game::class)
        ->and($onTeam->topic)->toBeInstanceOf(Team::class)
        ->and($onGroup->topic)->toBeInstanceOf(Group::class)
        // The stored discriminator is the short alias, never a class name.
        ->and($onGroup->topic_type)->toBe('group');
});

it('throws rather than conversing about an unmapped model', function () {
    // The map is a product decision: exactly game, team, group (plus User's
    // identity entry for its pre-existing morphs). A post pointed at
    // anything else must fail loudly at write time, not resolve a class
    // name into the column. Article stands in for "anything else".
    $post = new ConversationPost;

    expect(fn () => $post->topic()->associate(new Article))
        ->toThrow(ClassMorphViolationException::class);
});

it('treats posts as immutable ledger rows', function () {
    $post = ConversationPost::factory()->create();

    expect($post->created_at)->not->toBeNull()
        ->and(ConversationPost::UPDATED_AT)->toBeNull();
});

it('builds weeks from the new factory with pinned dates', function () {
    $week = Week::factory()->create();

    expect($week->start_date->toDateString())->toBe('2026-09-01')
        ->and($week->number)->toBe(1);
});
