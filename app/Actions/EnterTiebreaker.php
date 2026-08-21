<?php

namespace App\Actions;

use App\Exceptions\HandleRequired;
use App\Exceptions\NotGroupMember;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\PickLocked;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\User;
use InvalidArgumentException;

/**
 * The entrant's tiebreaker call: total points of the designated game.
 *
 * Locks at THAT game's kickoff — the same per-game rule as every pick, no
 * special case. Null stays the honest default: a never-entered prediction
 * loses the tiebreak to any entered one, and nothing writes a guess on the
 * user's behalf.
 */
class EnterTiebreaker
{
    /**
     * @throws PickemParticipationGated when the entrant is unverified
     * @throws HandleRequired when no handle has been claimed
     * @throws NotGroupMember when the entrant is outside the group
     * @throws PickLocked when the tiebreaker game has kicked off
     */
    public function handle(User $user, Slate $slate, int $total): SlateEntry
    {
        if (! $user->hasVerifiedEmail()) {
            throw new PickemParticipationGated;
        }

        if ($user->handle === null) {
            throw new HandleRequired;
        }

        $slate->loadMissing(['contest', 'tiebreakerGame.game']);

        $isMember = GroupMember::query()
            ->where('group_id', $slate->contest->group_id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            throw new NotGroupMember;
        }

        if (! $slate->isPublished() || $slate->tiebreakerGame === null) {
            throw new InvalidArgumentException("Slate {$slate->id} has no open tiebreaker to call.");
        }

        if ($slate->tiebreakerGame->game->hasKickedOff()) {
            throw new PickLocked;
        }

        // The ceiling depends on the question: points and yards live on
        // different scales, and the metric knows its own.
        $max = $slate->tiebreaker_metric?->maxPrediction() ?? 200;

        if ($total < 0 || $total > $max) {
            throw new InvalidArgumentException("{$total} is not a plausible answer to this tiebreaker.");
        }

        return SlateEntry::query()->updateOrCreate(
            ['slate_id' => $slate->id, 'user_id' => $user->id],
            ['tiebreaker_total' => $total],
        );
    }
}
