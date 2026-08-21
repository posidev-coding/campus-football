<?php

namespace App\Actions;

use App\Exceptions\GroupNeedsCommissioner;
use App\Models\Group;
use App\Models\User;

/**
 * Walk out of a group. Picks and wallet history stay — leaving is a
 * membership fact, not an eraser.
 *
 * The commissioner leaves LAST: while anyone else remains the group needs
 * running, so the commissioner's exit is blocked rather than silently
 * beheading the group. The final member's exit deletes the group — an empty
 * group is not a thing anyone returns to, and the code should die with it.
 *
 * PUBLIC ROOMS are the exception on both counts: the house runs them (no
 * commissioner to hold last), and a room persists at its URL forever —
 * History links back to it — so an emptied room stays.
 */
class LeaveGroup
{
    /**
     * @throws GroupNeedsCommissioner when the commissioner leaves too early
     */
    public function handle(User $user, Group $group): void
    {
        $membership = $group->memberships()->where('user_id', $user->id)->first();

        if ($membership === null) {
            return;
        }

        if ($membership->isCommissioner() && $group->memberships()->count() > 1) {
            throw new GroupNeedsCommissioner;
        }

        $membership->delete();

        if (! $group->isRoom() && $group->memberships()->count() === 0) {
            $group->delete();
        }
    }
}
