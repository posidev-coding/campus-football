<?php

namespace App\Actions;

use App\Exceptions\NotGroupCommissioner;
use App\Models\Group;
use App\Models\User;

/**
 * The commissioner shows somebody the door. Their picks and history stay —
 * removal ends membership, it does not rewrite the season.
 *
 * The authority check lives HERE, not in the screen: this is reachable from
 * a public Livewire method, and a screen's @if is a suggestion, not a gate.
 */
class RemoveGroupMember
{
    /**
     * @throws NotGroupCommissioner when the caller does not run this group
     */
    public function handle(User $actor, Group $group, User $member): void
    {
        $actorSeat = $group->memberships()->where('user_id', $actor->id)->first();

        if ($actorSeat === null || ! $actorSeat->isCommissioner()) {
            throw new NotGroupCommissioner;
        }

        // The commissioner cannot remove themselves — that door is
        // LeaveGroup's, which knows a group must not lose its runner.
        if ($member->id === $actor->id) {
            return;
        }

        $group->memberships()->where('user_id', $member->id)->delete();
    }
}
