<?php

namespace App\Actions;

use App\Exceptions\ContestFull;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\WalletTooLight;
use App\Jobs\SpawnSuccessorRoom;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\User;
use App\Models\WalletEntry;
use Illuminate\Support\Facades\DB;

/**
 * Take a seat in a group — reached by invite code for private groups and
 * directly for lobbies; the screen resolves which, this seats the member.
 *
 * Joining twice is a no-op rather than an error, the FollowTeam idempotency
 * shape: the button you already pressed must never scold you.
 *
 * PUBLIC ROOMS add three rules and one side effect. A room refuses a seat
 * once its cap is reached or its week is already being played (a seat you
 * cannot pick from is not a seat), and a MARQUEE room refuses one the
 * wallet cannot cover. And the join that FILLS the room spawns the next
 * one — through the atomic `filled_at` claim, so two racing joiners
 * provision exactly one Room N+1. The hourly sweep is the belt; this hook
 * is the suspenders that keeps the lobby stocked in real time.
 */
class JoinGroup
{
    public function __construct(
        private GrantWalletEntry $wallet,
    ) {}

    /**
     * @throws PickemParticipationGated when the joiner is unverified
     * @throws ContestFull when a public room has no seat to give
     * @throws WalletTooLight when the seat is priced and the wallet is short
     */
    public function handle(User $user, Group $group): void
    {
        if (! $user->hasVerifiedEmail()) {
            throw new PickemParticipationGated;
        }

        if ($group->memberships()->where('user_id', $user->id)->exists()) {
            return;
        }

        if ($group->isRoom()) {
            $this->guardRoom($group);
        }

        // Seat first, then charge, INSIDE one transaction: a failure
        // between them rolls both back, and if the two ever had to be
        // ordered the safe direction is a free seat rather than a spent
        // credit and no room.
        DB::transaction(function () use ($user, $group) {
            GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $user->id,
                'role' => GroupMember::MEMBER,
            ]);

            $this->charge($user, $group);
        });

        // Once-ever, whichever group was first — the key is the cap.
        $this->wallet->handle(
            $user,
            GrantWalletEntry::FIRST_GROUP_XP,
            0,
            GrantWalletEntry::REASON_FIRST_GROUP,
            GrantWalletEntry::REASON_FIRST_GROUP,
        );

        if ($group->isRoom() && $group->member_cap !== null) {
            $this->spawnIfFilled($group);
        }
    }

    /**
     * Ice one down for a marquee seat.
     *
     * NO SPEND MAY TAKE A WALLET NEGATIVE, and the ledger has deliberately
     * no balance column to enforce that with — totals are SUMs. So the read
     * and the write are serialized on the JOINER'S OWN ROW: without the
     * lock, two taps on two Spotlight cards with one credit in hand both
     * read a balance of 1, both pass, and the wallet ends at −1. Locking
     * the user rather than the room is right because the constraint belongs
     * to the wallet, not to the seat.
     *
     * The spend is keyless on purpose — a contest entry spends every entry,
     * and leaving and coming back is a second seat, honestly bought.
     *
     * @throws WalletTooLight
     */
    private function charge(User $user, Group $group): void
    {
        $price = $group->entryCredits();

        if ($price === 0) {
            return;
        }

        User::query()->whereKey($user->id)->lockForUpdate()->value('id');

        $balance = (int) WalletEntry::query()->where('user_id', $user->id)->sum('credits');

        if ($balance < $price) {
            throw new WalletTooLight;
        }

        $this->wallet->handle($user, 0, -$price, GrantWalletEntry::REASON_ROOM_ENTRY);
    }

    /** @throws ContestFull */
    private function guardRoom(Group $room): void
    {
        if ($room->member_cap !== null
            && $room->memberships()->count() >= $room->member_cap) {
            throw new ContestFull;
        }

        // A week already being played takes no walk-ons: every seat in a
        // room exists to PICK, and the picks are locked.
        $slate = Slate::query()
            ->whereHas('contest', fn ($q) => $q->where('group_id', $room->id))
            ->where('week_id', $room->week_id)
            ->with('games.game:id,kickoff_at,status,completed')
            ->first();

        if ($slate !== null && $slate->games->isNotEmpty()
            && $slate->games->every(fn ($slateGame) => $slateGame->game->hasKickedOff())) {
            throw new ContestFull;
        }
    }

    /**
     * The join that fills the room provisions the next one — ONCE, however
     * many joiners land at the cap together: `filled_at` is the claim, and
     * only the update that stamps it (one row affected) DISPATCHES. The
     * spawning itself is SpawnSuccessorRoom's queued work now — it is
     * multi-hundred-ms of writes, and it used to run inline in the join
     * request; the hourly pickem:open-lobbies sweep is the belt.
     */
    private function spawnIfFilled(Group $room): void
    {
        if ($room->memberships()->count() < $room->member_cap) {
            return;
        }

        $claimed = Group::query()
            ->whereKey($room->id)
            ->whereNull('filled_at')
            ->update(['filled_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        SpawnSuccessorRoom::dispatch($room->id);
    }
}
