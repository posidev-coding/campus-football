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
use Illuminate\Support\Facades\DB;

/**
 * Take a seat in a group — reached by invite code for private groups and
 * directly for lobbies; the screen resolves which, this seats the member.
 *
 * Joining twice is a no-op rather than an error, the FollowTeam idempotency
 * shape: the button you already pressed must never scold you.
 *
 * A PRIVATE GROUP seats an unverified account. The invite code is the
 * credential — somebody handed it over — and the seat itself earns and
 * risks nothing: picks, the Lock, the tiebreaker and every wallet write
 * stay behind the verified gate in their own actions. Before 2026-09-02
 * the gate sat here too, and the funnel it produced was: scan the QR,
 * register, land back on the same invite card with a button that refused
 * you, then lose the invite entirely when the verification click landed
 * on Home. Now the reader is IN the clubhouse, where the verify nudge is
 * the one thing between them and a pick. A seat that never verifies goes
 * with the account at User::VERIFICATION_GRACE_DAYS.
 *
 * PUBLIC ROOMS keep the gate and add three rules and one side effect. A
 * house-run room's seats are capped, so an unverified drop-in would hold
 * one it can never pick from. A room refuses a seat once its cap is
 * reached or its week is already being played (a seat you cannot pick from
 * is not a seat), and a MARQUEE room refuses one the wallet cannot cover.
 * And the join that FILLS the room spawns the next one — through the
 * atomic `filled_at` claim, so two racing joiners provision exactly one
 * Room N+1. The hourly sweep is the belt; this hook is the suspenders that
 * keeps the lobby stocked in real time.
 */
class JoinGroup
{
    public function __construct(
        private GrantWalletEntry $wallet,
    ) {}

    /**
     * @throws PickemParticipationGated when an unverified joiner asks for a PUBLIC seat
     * @throws ContestFull when a public room has no seat to give
     * @throws WalletTooLight when the seat is priced and the wallet is short
     */
    public function handle(User $user, Group $group): void
    {
        if ($group->isLobby() && ! $user->hasVerifiedEmail()) {
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

        // Once-ever, whichever group was first — the key is the cap. An
        // unverified seat earns nothing (the one earn before verification
        // is the first-team seed); the key is still unspent, so the first
        // seat taken AFTER verifying pays it.
        if ($user->hasVerifiedEmail()) {
            $this->wallet->handle(
                $user,
                GrantWalletEntry::FIRST_GROUP_XP,
                0,
                GrantWalletEntry::REASON_FIRST_GROUP,
                GrantWalletEntry::REASON_FIRST_GROUP,
            );
        }

        if ($group->isRoom() && $group->member_cap !== null) {
            $this->spawnIfFilled($group);
        }
    }

    /**
     * Ice one down for a marquee seat.
     *
     * A free shelf prices at zero and spend() returns without touching the
     * wallet, so the caller never has to ask whether this room charges.
     * The no-negative law and the lock that enforces it live in
     * {@see GrantWalletEntry::spend()}, shared with the wager.
     *
     * @throws WalletTooLight
     */
    private function charge(User $user, Group $group): void
    {
        $this->wallet->spend($user, $group->entryCredits(), GrantWalletEntry::REASON_ROOM_ENTRY);
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
