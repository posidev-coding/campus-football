<?php

use App\Actions\PublishSlate;
use App\Enums\ContestMode;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\SlateEntry;
use App\Models\User;
use App\Support\PickemPulse;
use Illuminate\Support\Facades\DB;

/*
 * THE PULSE — the lean read Home's picks strip (and later the next-up
 * ladder and the nav dot) stands on. These tests hold its two contracts:
 * null-shaped emptiness (the flag, memberships, published slates) and a
 * query cost that stays flat however many groups the viewer holds.
 */

it('is empty behind the closed flag, and over a draft-only week', function () {
    config()->set('cfb.pickem_open', false);

    [$commissioner, , $contest] = pickemContest(ContestMode::Classic);
    // pickemAdmin() mints admins, and admins see through the flag.
    $commissioner->forceFill(['admin' => false])->save();

    expect(PickemPulse::cards($commissioner->fresh()))->toBeEmpty();

    // Flag open but only a DRAFT slate: still no card — a draft is not a
    // week anybody can act on, and the caller skips rather than invents.
    config()->set('cfb.pickem_open', true);
    PickemPulse::flush();
    pickemDraftSlate($contest);

    expect(PickemPulse::cards($commissioner->fresh()))->toBeEmpty();
});

it('carries a published card: state, progress, entry, kickoff', function () {
    config()->set('cfb.pickem_open', true);

    [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
    $slate = pickemDraftSlate($contest);
    app(PublishSlate::class)->handle($commissioner, $slate);
    $slate = $slate->fresh();

    $card = PickemPulse::cards($commissioner)->sole();

    expect($card['group']->id)->toBe($group->id)
        ->and($card['state'])->toBe('upcoming')
        ->and($card['made'])->toBe(0)
        ->and($card['total'])->toBe(10)
        ->and($card['entryIn'])->toBeFalse()
        ->and($card['firstKick'])->not->toBeNull();

    // Every game picked plus the tiebreaker answered = the entry is in —
    // the same derived rule MakesPicks::entryComplete() states.
    foreach ($slate->games()->with('game')->get() as $slateGame) {
        Pick::factory()->create([
            'slate_game_id' => $slateGame->id,
            'user_id' => $commissioner->id,
            'picked_team_id' => $slateGame->game->home_team_id,
        ]);
    }
    SlateEntry::factory()->create([
        'slate_id' => $slate->id,
        'user_id' => $commissioner->id,
        'tiebreaker_total' => 48,
    ]);

    PickemPulse::flush();
    $card = PickemPulse::cards($commissioner)->sole();

    expect($card['made'])->toBe(10)
        ->and($card['entryIn'])->toBeTrue();
});

it('costs the same for three groups as for one, and nothing when memoized', function () {
    config()->set('cfb.pickem_open', true);

    [$one, $groupA, $contestA] = pickemContest(ContestMode::Classic);
    app(PublishSlate::class)->handle($one, pickemDraftSlate($contestA));

    $three = pickemAdmin();
    GroupMember::factory()->create(['group_id' => $groupA->id, 'user_id' => $three->id]);

    foreach (range(1, 2) as $i) {
        [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
        app(PublishSlate::class)->handle($commissioner, pickemDraftSlate($contest));
        GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $three->id]);
    }

    $queries = function (User $user): int {
        PickemPulse::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        PickemPulse::cards($user);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // The first ask pays the shared calendar warms every screen amortizes;
    // discard it so the comparison is about group count, not cache
    // temperature.
    $queries($one);

    expect($queries($three))->toBe($queries($one));

    // Memoized per request: the second ask costs nothing at all.
    PickemPulse::flush();
    PickemPulse::cards($three);

    DB::flushQueryLog();
    DB::enableQueryLog();
    PickemPulse::cards($three);

    expect(count(DB::getQueryLog()))->toBe(0);

    DB::disableQueryLog();
});
