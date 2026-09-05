<?php

namespace App\Actions;

use App\Exceptions\CannotInvite;
use App\Exceptions\NotGroupMember;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\User;
use App\Notifications\GroupInviteReceived;
use App\Support\Invitables;

/**
 * One member asks one named person into their private group, in the app.
 *
 * The code-by-text invite (`docs/plans/app-invites.md`) already covers the
 * stranger and the group chat. What it cannot do is reach somebody you only
 * know from inside the product — you have no number for @dave44, and there
 * is nowhere to paste a link to him. This is that door, and it is the whole
 * of it: the invite lands in his inbox carrying the SAME `/join/{CODE}`
 * link, so accepting is the join screen we already ship. No second accept
 * path, no second seating rule, nothing new that can disagree with JoinGroup
 * about who gets a seat.
 *
 * ROOMS ARE NOT INVITABLE and that is not an oversight — it is the decision
 * `group.blade.php` already carries ("rooms are joined from the lobby, not
 * by invitation"): a room fills, goes stale weekly, and an invite to one is
 * a promise the lobby may have already broken.
 *
 * Every guard lives here rather than in the picker. The screen's list is
 * presentation; this is the gate, and `Invitables::allows()` is the same
 * co-membership rule the list is built from, asked of one person.
 *
 * Sending twice is a no-op, the FollowTeam idempotency shape — and here it
 * is also the anti-spam: the unique index means one standing ask per person
 * per group, so a repeated tap cannot become a repeated notification.
 */
class InviteUserToGroup
{
    /**
     * @throws NotGroupMember when the sender holds no seat in the group
     * @throws CannotInvite when the group is a room, or the recipient is not
     *                      somebody the sender has played with
     */
    public function handle(User $actor, Group $group, User $invitee): void
    {
        if ($group->isLobby()) {
            throw new CannotInvite;
        }

        if (! $group->memberships()->where('user_id', $actor->id)->exists()) {
            throw new NotGroupMember;
        }

        if (! Invitables::allows($actor, $invitee)) {
            throw new CannotInvite;
        }

        // Already seated: silence, not a scold. The picker filters members
        // out, so reaching this is a race, and the outcome the sender wanted
        // has already happened.
        if ($group->memberships()->where('user_id', $invitee->id)->exists()) {
            return;
        }

        $invite = GroupInvite::firstOrCreate([
            'group_id' => $group->id,
            'invitee_id' => $invitee->id,
        ], [
            'inviter_id' => $actor->id,
        ]);

        // Only a NEW ask makes a noise. A second tap on a button whose row
        // already reads "Invited" must not put a second row in somebody's
        // inbox.
        if (! $invite->wasRecentlyCreated) {
            return;
        }

        $invitee->notify(new GroupInviteReceived($group, $actor));
    }
}
