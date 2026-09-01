<?php

namespace App\Actions;

use App\Exceptions\NotGroupCommissioner;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Give the commissioner's seat to somebody else.
 *
 * The schema was built for this on day one — the pickem migration's own
 * comment on `group_members.role` reads "so a handoff is one UPDATE" — and
 * the action was never written, which left a real dead end: `LeaveGroup`
 * refuses to let a commissioner out while anybody else remains, so a
 * founder who wanted to hand their league over could neither hand it over
 * nor leave. This is the verb that unblocks both.
 *
 * An Action rather than a screen write because it has consequences beyond
 * two rows: the seat is what `SlateAuthority::commissioner()` checks, so
 * this decides who may build, publish and settle the card from here on.
 *
 * Both writes ride ONE transaction. A crash between them is the failure
 * that matters — a group with two commissioners is merely untidy, but a
 * group with NONE cannot publish a slate again and no screen can repair
 * it, so the demotion must never land without the promotion.
 */
class HandOffCommissioner
{
    /**
     * @throws NotGroupCommissioner when the actor does not hold the seat
     * @throws InvalidArgumentException when the group cannot have one, or
     *                                  the recipient is not a member of it
     */
    public function handle(User $actor, Group $group, User $recipient): void
    {
        /*
         * Rooms have no commissioner seat at all — the house runs them
         * (see SpawnPublicContest). Refusing here rather than in the
         * screen keeps a public Livewire method from inventing one.
         */
        if ($group->isRoom()) {
            throw new InvalidArgumentException('A public room has no commissioner seat to hand off.');
        }

        $seat = $group->memberships()->where('user_id', $actor->id)->first();

        if ($seat === null || ! $seat->isCommissioner()) {
            throw new NotGroupCommissioner;
        }

        // Handing the seat to yourself is a no-op, not an error: the
        // FollowTeam idempotency shape, and the alternative is demoting
        // the only commissioner and promoting them back inside the same
        // transaction for no reason.
        if ($recipient->id === $actor->id) {
            return;
        }

        $successor = $group->memberships()->where('user_id', $recipient->id)->first();

        /*
         * The recipient must ALREADY be seated. Promoting a stranger would
         * make this a second way to join a private group, reachable by
         * anyone who could name a user — the invite code is the only door.
         */
        if ($successor === null) {
            throw new InvalidArgumentException('Only a member of the group can be made its commissioner.');
        }

        DB::transaction(function () use ($seat, $successor): void {
            $successor->update(['role' => GroupMember::COMMISSIONER]);
            $seat->update(['role' => GroupMember::MEMBER]);
        });
    }
}
