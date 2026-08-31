<?php

use App\Actions\PublishSlate;
use App\Actions\SpawnPublicContest;
use App\Enums\ContestMode;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\User;
use App\Support\Cadence;
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

describe('the next-up ladder', function () {
    beforeEach(function () {
        config()->set('cfb.pickem_open', true);
        // Pin the clock inside the deadline window (Monday noon ET before
        // the Sep 5 card): the build rung and the 24-hour calm are both
        // clock-derived, and an unpinned "now" flips branches midweek.
        $this->travelTo('2026-08-31 16:00:00');
        Cadence::flush();
    });

    it('walks a member from fresh slate to due picks to the tiebreaker to calm', function () {
        [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
        $slate = pickemDraftSlate($contest);
        app(PublishSlate::class)->handle($commissioner, $slate);
        $slate = $slate->fresh();

        $member = User::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

        expect(PickemPulse::nudge($member)['key'])->toBe('picks.next.fresh');

        $games = $slate->games()->with('game')->get();
        Pick::factory()->create([
            'slate_game_id' => $games[0]->id,
            'user_id' => $member->id,
            'picked_team_id' => $games[0]->game->home_team_id,
        ]);

        PickemPulse::flush();
        $nudge = PickemPulse::nudge($member);

        expect($nudge['key'])->toBe('picks.next.due')
            ->and($nudge['replace']['picks'])->toBe('9 picks')
            ->and($nudge['cta'])->toBe('Finish your picks');

        foreach ($games->slice(1) as $slateGame) {
            Pick::factory()->create([
                'slate_game_id' => $slateGame->id,
                'user_id' => $member->id,
                'picked_team_id' => $slateGame->game->home_team_id,
            ]);
        }

        PickemPulse::flush();
        expect(PickemPulse::nudge($member)['key'])->toBe('picks.next.tiebreaker');

        SlateEntry::factory()->create([
            'slate_id' => $slate->id,
            'user_id' => $member->id,
            'tiebreaker_total' => 48,
        ]);

        // Entry in, kickoff five days out: nothing worth saying — the
        // done thing is the dismissal.
        PickemPulse::flush();
        expect(PickemPulse::nudge($member))->toBeNull();

        // Inside a day of kickoff: the calm locked-in line.
        $this->travelTo('2026-09-05 12:00:00');
        Cadence::flush();
        PickemPulse::flush();

        expect(PickemPulse::nudge($member)['key'])->toBe('picks.next.locked');
    });

    it('sends a commissioner through the build door only inside the window', function () {
        [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);

        // A buildable Saturday: lined games, nothing published.
        [$season, $week] = pickemSeasonWeek();

        foreach (range(1, 12) as $i) {
            pickemOdd(pickemGame($season, $week));
        }

        $nudge = PickemPulse::nudge($commissioner);

        expect($nudge['key'])->toBe('picks.next.build')
            ->and($nudge['cta'])->toBe('Build the slate');

        // Past the Thursday-noon deadline the door closes, and the ladder
        // falls through to the quiet-group ask, not a stale build call.
        $this->travelTo('2026-09-03 20:00:00');
        Cadence::flush();
        PickemPulse::flush();

        expect(PickemPulse::nudge($commissioner)['key'])->toBe('picks.next.invite');
    });

    it('celebrates the settled week, the win louder than the placing', function () {
        [$commissioner, $group, $contest] = pickemContest(ContestMode::Classic);
        $slate = pickemDraftSlate($contest);
        app(PublishSlate::class)->handle($commissioner, $slate);
        $slate = $slate->fresh();

        $slate->update(['status' => Slate::SETTLED, 'settled_at' => now()]);
        SlateEntry::factory()->create([
            'slate_id' => $slate->id,
            'user_id' => $commissioner->id,
            'final_points' => 90,
            'won' => true,
        ]);

        $nudge = PickemPulse::nudge($commissioner);

        expect($nudge['key'])->toBe('picks.next.won')
            ->and($nudge['href'])->toContain('view=results');

        SlateEntry::query()->update(['won' => false]);
        PickemPulse::flush();

        expect(PickemPulse::nudge($commissioner)['key'])->toBe('picks.next.settled');
    });

    it('stays silent for the unverified, and shows a seatless reader the way in', function () {
        $unverified = User::factory()->unverified()->create();

        expect(PickemPulse::nudge($unverified))->toBeNull();

        // Verified but seatless with no rooms open: zero open rooms is a
        // count with no decision attached, so it says nothing.
        $reader = User::factory()->create();

        expect(PickemPulse::nudge($reader))->toBeNull();

        // One open room turns the silence into a door. The spawn wants the
        // played week's own clock (the PublicContestTest fixture's date).
        $this->travelTo('2026-09-02 12:00:00');
        Cadence::flush();

        [$season, $week] = pickemSeasonWeek();

        foreach (range(1, 16) as $i) {
            $game = pickemGame($season, $week);
            pickemOdd($game);
            $game->predictor()->create(['matchup_quality' => 95 - $i]);
        }

        app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);

        PickemPulse::flush();
        $nudge = PickemPulse::nudge($reader);

        expect($nudge['key'])->toBe('picks.next.join')
            ->and($nudge['replace']['rooms'])->toBe('1 public room');
    });
});
