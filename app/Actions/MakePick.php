<?php

namespace App\Actions;

use App\Exceptions\HandleRequired;
use App\Exceptions\NotGroupMember;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\PickLocked;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Models\User;
use InvalidArgumentException;

/**
 * One call on one game, before it kicks.
 *
 * Every gate lives HERE — verified email, claimed handle, group
 * membership, published slate, the temporal lock, and the team actually
 * being in the game — because this is reachable from a public Livewire
 * method and a sheet's disabled button is presentation, not enforcement.
 *
 * Changing your mind is the same door: the pick upserts until kickoff.
 * What never happens here is a pick on the user's behalf — a missed pick
 * stays an absent row, worth zero, forever.
 *
 * The first pick of a slate seats the user (slate_entries) and pays the
 * entry XP once, keyed `slate:{id}:in` — the wallet's unique index is the
 * cap, so changing picks all week pays nothing twice.
 */
class MakePick
{
    public function __construct(private GrantWalletEntry $wallet) {}

    /**
     * @throws PickemParticipationGated when the picker is unverified
     * @throws HandleRequired when no handle has been claimed
     * @throws NotGroupMember when the picker is outside the group
     * @throws PickLocked when the game has kicked off
     */
    public function handle(User $user, SlateGame $slateGame, int $teamId): Pick
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
            throw new InvalidArgumentException("Slate {$slate->id} is not published; there is nothing to pick yet.");
        }

        if ($slateGame->game->hasKickedOff()) {
            throw new PickLocked;
        }

        if (! in_array($teamId, [$slateGame->game->home_team_id, $slateGame->game->away_team_id], true)) {
            throw new InvalidArgumentException("Team {$teamId} is not in game {$slateGame->game_id}.");
        }

        $pick = Pick::query()->updateOrCreate(
            ['slate_game_id' => $slateGame->id, 'user_id' => $user->id],
            ['picked_team_id' => $teamId],
        );

        SlateEntry::query()->firstOrCreate(['slate_id' => $slate->id, 'user_id' => $user->id]);

        $this->wallet->handle(
            $user,
            GrantWalletEntry::PICKEM_ENTERED_XP,
            0,
            GrantWalletEntry::REASON_PICKEM_ENTERED,
            "slate:{$slate->id}:in",
        );

        return $pick;
    }
}
