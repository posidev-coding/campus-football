<?php

use App\Enums\ContentRating;
use App\Models\User;
use App\Support\Voice;

/*
 * The vocabulary sweep: THE SLATE is the product's word, and "board" may
 * never sneak back in through one stray line — the Georgia-sweep pattern
 * applied to a word. Values only: the engine's key NAMES are contracts
 * and keep whatever they were born with.
 */

it('never says "board" on a pick\'em surface, in any register', function () {
    $lines = (new ReflectionClass(Voice::class))->getConstant('LINES');

    $families = [
        'picks.', 'groups.', 'group.', 'lobby.', 'contest.', 'create.',
        'mode.', 'wizard.', 'history.', 'leaderboard.', 'notify.mode_changed',
        'join.',
    ];

    $violations = [];

    foreach ($lines as $key => $variants) {
        $inScope = false;

        foreach ($families as $family) {
            if (str_starts_with($key, $family)) {
                $inScope = true;

                break;
            }
        }

        if (! $inScope) {
            continue;
        }

        foreach ($variants as $register => $line) {
            if (preg_match('/board/i', str_ireplace('leaderboard', '', $line)) === 1) {
                $violations[] = "{$key}.{$register}: {$line}";
            }
        }
    }

    expect($violations)->toBe([], implode(' | ', $violations));
});

it('speaks every register on the rebuild\'s new families', function (string $key) {
    $pg = Voice::line($key, ['group' => 'X', 'mode' => 'X', 'code' => 'X', 'name' => 'X', 'due' => 'X'], for: User::factory()->make(['content_rating' => ContentRating::Pg]));
    $r = Voice::line($key, ['group' => 'X', 'mode' => 'X', 'code' => 'X', 'name' => 'X', 'due' => 'X'], for: User::factory()->make(['content_rating' => ContentRating::R]));

    expect($pg)->not->toBe('')
        ->and($r)->not->toBe('')
        ->and($pg)->not->toBe($r);
})->with([
    'group.slate.waiting',
    'group.slate.build_prompt',
    'group.season.empty',
    'group.mode_changed',
    'create.subheading',
    'create.mode.hint',
    'mode.change.warning',
    'mode.change.blocked.used',
    'wizard.lines.hint',
    'wizard.deadline',
    'wizard.published',
    'lobby.publics.empty',
    'lobby.first_run.body',
    'lobby.needs.subheading',
    'lobby.rules.subheading',
    'contest.room.full',
    'history.empty',
    'leaderboard.empty',
    'notify.mode_changed.body',
    'groups.invite.share_text',
    'join.pitch',
    'join.miss',
    'join.room.played',
    'mode.change.pick_one',
    'picks.publish.featured_metric',
    'picks.bear.tagline.favorites',
    'picks.bear.tagline.dogs',
    'picks.bear.tagline.home',
    'picks.bear.tagline.road',
    'picks.bear.tagline.alternating',
]);
