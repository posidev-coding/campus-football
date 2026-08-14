<?php

namespace App\Actions;

use App\Exceptions\HandleRequired;
use App\Exceptions\NotGroupMember;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\PickLocked;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\SlateGame;
use App\Models\User;
use InvalidArgumentException;

/**
 * Stake or pull the Woodshed's Lock wager on the featured game.
 *
 * Same gate order as MakePick — verified email, claimed handle,
 * membership, published board, the temporal kickoff lock — because this
 * too is reachable from a public Livewire method. On top of those, three
 * rules of the wager itself: the mode must offer the Lock at all, only
 * the FEATURED game (the designated tiebreaker game — one designation,
 * two jobs) is eligible, and there must be a pick to stake — the toggle
 * never invents a side. Unstaking before kickoff is the same door with
 * `false`.
 *
 * No XP moves here: the wager's price is paid in points at grading.
 */
class LockPick
{
    /**
     * @throws PickemParticipationGated when the picker is unverified
     * @throws HandleRequired when no handle has been claimed
     * @throws NotGroupMember when the picker is outside the group
     * @throws PickLocked when the game has kicked off
     */
    public function handle(User $user, SlateGame $slateGame, bool $locked): Pick
    {
        if (! $user->hasVerifiedEmail()) {
            throw new PickemParticipationGated;
        }

        if ($user->handle === null) {
            throw new HandleRequired;
        }

        $slateGame->loadMissing(['slate.contest', 'game']);
        $slate = $slateGame->slate;

        $isMember = GroupMember::query()
            ->where('group_id', $slate->contest->group_id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            throw new NotGroupMember;
        }

        if (! $slate->isPublished()) {
            throw new InvalidArgumentException("Slate {$slate->id} is not published; there is nothing to lock yet.");
        }

        if ($slateGame->game->hasKickedOff()) {
            throw new PickLocked;
        }

        $engine = $slate->contest->mode->engine($slate->contest->settings);

        if (! $engine->supportsLock()) {
            throw new InvalidArgumentException("Contest {$slate->contest_id}'s mode has no Lock to stake.");
        }

        if ($slateGame->id !== $slate->tiebreaker_slate_game_id) {
            throw new InvalidArgumentException("Slate game {$slateGame->id} is not the featured game; only the featured game takes the Lock.");
        }

        $pick = Pick::query()
            ->where('slate_game_id', $slateGame->id)
            ->where('user_id', $user->id)
            ->first();

        if ($pick === null) {
            throw new InvalidArgumentException('The Lock stakes an existing pick; pick a side first.');
        }

        $pick->update(['locked' => $locked]);

        return $pick;
    }
}
