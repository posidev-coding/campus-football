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
        'mode.', 'wizard.', 'history.', 'leaderboard.', 'notify.',
        'join.', 'talk.',
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
    /*
     * One replacement set for every key in the dataset. It has to carry the
     * union of their tokens: a token missing from here renders as a literal
     * ":thing" in the output, which this test's own assertions would not
     * notice — the no-stray-token sweep below is what catches that.
     */
    $replace = [
        'group' => 'X', 'mode' => 'X', 'code' => 'X', 'name' => 'X', 'due' => 'X',
        'owed' => '3', 'total' => '10', 'count' => '2', 'when' => 'noon',
        'week' => 'Week 1', 'points' => '14', 'xp' => '100', 'place' => '3rd',
        'field' => '9', 'winner' => 'X', 'others' => 'X', 'rival' => 'X',
        'margin' => '4', 'max' => '200', 'list' => 'Triple Option · Woodshed',
        // The next-up slot's pre-pluralized tokens, and its clock.
        'picks' => '3 picks', 'rooms' => '3 public rooms', 'time' => 'Sat 7:30pm',
        // The app invite's share text is personalized by the SHARER's
        // first name; without this the line renders a literal ":inviter".
        'inviter' => 'X',
    ];

    $pg = Voice::line($key, $replace, for: User::factory()->make(['content_rating' => ContentRating::Pg]));
    $r = Voice::line($key, $replace, for: User::factory()->make(['content_rating' => ContentRating::R]));

    expect($pg)->not->toBe('')
        ->and($r)->not->toBe('')
        ->and($pg)->not->toBe($r);
})->with([
    'group.slate.waiting',
    'group.slate.build_prompt',
    'group.slate.thin',
    'group.room.no_card',
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
    // My Picks' hero and its Results tab: the line over the one card
    // closest to locking, and the tab before anything has settled.
    'picks.hero.zinger',
    // The pick surface's one unprompted line, on the act that finishes
    // the entry.
    'picks.entry.celebration',
    // The weekly-win payoff banner atop Results, singular and plural —
    // the one celebration that arrives rather than firing on an act.
    'picks.payoff.banner',
    'picks.payoff.banner_many',
    // The week tab's state card, where the ask used to be.
    'picks.allin.body',
    'picks.results.empty',
    'picks.next.due',
    'picks.next.fresh',
    'picks.next.tiebreaker',
    'picks.next.build',
    'picks.next.invite',
    'picks.next.live',
    'picks.next.won',
    'picks.next.settled',
    'picks.next.locked',
    'picks.next.join',
    'lobby.rules.subheading',
    // The two products, told apart: the definitions under My Picks' two
    // section headings (My Groups / Week N Contests), the public half
    // named over the first run's lobby door, the first run's group path,
    // the store's own framing, and the two lines that say what a private
    // group is and what a room whose Saturday is gone is.
    'picks.groups.subheading',
    'picks.contests.subheading',
    'picks.rooms.subheading',
    'picks.first_run.group',
    'lobby.intro.zinger',
    'group.private.frame',
    'group.room.past',
    'contest.room.full',
    'history.empty',
    'leaderboard.empty',
    'notify.mode_changed.body',
    'groups.invite.share_text',
    // The upload that did not land — the storage side's refusal, said
    // on the icon's and the photo's own error lines instead of a 500.
    'groups.icon.failed',
    'account.photo.failed',
    'join.pitch',
    'join.miss',
    'join.room.played',
    // The app invite — a /join link with no code behind it, where the
    // copy IS the screen because there is no group to preview.
    'join.app.heading',
    'join.app.body',
    'join.app.hint',
    'join.app.share_text',
    'mode.change.pick_one',
    'picks.publish.featured_metric',
    'lobby.shelf.also',
    // The lobby's room-type subtabs: a filtered tab with nothing on it,
    // which has to say where the rooms went in every register.
    'lobby.shelf.empty',
    'home.pickem.live',
    'onboarding.guest.body_live',
    'picks.tiebreaker.invalid',
    'picks.locked.notice',
    'picks.claim.reason',
    'picks.bear.tagline.favorites',
    'picks.bear.tagline.dogs',
    'picks.bear.tagline.home',
    'picks.bear.tagline.road',
    'picks.bear.tagline.alternating',
    // The weekly loop. Subjects are deliberately register-identical (the
    // fact never jokes), so they belong to the sweeps above, not to this one.
    'notify.reminder.body',
    'notify.reminder.push',
    'notify.reminder.sms',
    'notify.last_call.body',
    'notify.last_call.push',
    'notify.results.won.body',
    'notify.results.won.shared',
    'notify.results.lost.body',
    'notify.results.missed.body',
    'notify.results.exhibition',
    'notify.results.nemesis',
    'notify.results.nemesis.won',
    'notify.results.bear.beat',
    'notify.results.bear.lost',
    'notify.inbox.empty',
]);

it('leaves no stray token in the weekly loop\'s copy', function () {
    /*
     * Voice::line() returns '' for an unknown KEY, silently — and leaves a
     * ":token" standing where a replacement was not supplied, equally
     * silently. Both ship an email that looks broken to a reader and looks
     * fine to every other test, so the whole family is swept here.
     */
    $lines = (new ReflectionClass(Voice::class))->getConstant('LINES');

    $replace = [
        'group' => 'Vol Nation', 'owed' => '3', 'total' => '10', 'count' => '2',
        'when' => 'noon', 'week' => 'Week 1', 'points' => '14', 'xp' => '100',
        'place' => '3rd', 'field' => '9', 'winner' => 'dave', 'others' => 'dave',
        'rival' => 'dave', 'margin' => '4', 'mode' => 'Shotgun',
    ];

    foreach ($lines as $key => $variants) {
        if (! str_starts_with($key, 'notify.')) {
            continue;
        }

        foreach (array_keys($variants) as $register) {
            $rendered = Voice::line($key, $replace, for: User::factory()->make([
                'content_rating' => ContentRating::from($register),
            ]));

            expect($rendered)->not->toBe('', "{$key}.{$register} resolved empty")
                ->and($rendered)->not->toMatch('/:[a-z_]+/', "{$key}.{$register} left a token: {$rendered}");
        }
    }
});
