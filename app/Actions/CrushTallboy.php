<?php

namespace App\Actions;

use App\Exceptions\HandleRequired;
use App\Exceptions\NotGroupMember;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\PickLocked;
use App\Exceptions\WalletTooLight;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\SlateGame;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Crush a Tallboy on one game: +5 right, −5 wrong, one credit.
 *
 * The same gate order as MakePick and LockPick — verified email, claimed
 * handle, membership, published slate, the temporal kickoff lock — because
 * this too is reachable from a public Livewire method and a disabled
 * button is presentation, not enforcement. On top of those, the wager's
 * own three rules.
 *
 * It differs from the Lock on exactly three points, and they are the
 * design: ANY game on the card is eligible rather than only the featured
 * one, the engine gate is supportsTallboy() (evaluated against the
 * contest's own frozen slate size), and it SPENDS.
 *
 * ONE WAGER PER SLATE. That is what the ~15% leverage ceiling is a
 * guarantee about — two wagers would be ±10 and a third would break it —
 * so moving the Tallboy to a different game is a MOVE, not a second
 * purchase: the credit bought the week's wager, and it stays bought until
 * it is pulled. Pulling refunds it as a NEW POSITIVE ROW, never an edit
 * and never a deleted one; corrections are new rows, the way a bank does
 * it. Once the game it is riding has kicked off, the wager is live and
 * neither moves nor pulls.
 *
 * `picks.locked` is the column, shared with the Woodshed's Lock and needing
 * no migration, because a slate can only ever offer ONE wager and the two
 * are mutually exclusive by design.
 */
class CrushTallboy
{
    public function __construct(private GrantWalletEntry $wallet) {}

    /**
     * @throws PickemParticipationGated when the picker is unverified
     * @throws HandleRequired when no handle has been claimed
     * @throws NotGroupMember when the picker is outside the group
     * @throws PickLocked when this game — or the game already carrying the
     *                    wager — has kicked off
     * @throws WalletTooLight when the wallet cannot cover the stake
     */
    public function handle(User $user, SlateGame $slateGame, bool $staked): Pick
    {
        if (! $user->hasVerifiedEmail()) {
            throw new PickemParticipationGated;
        }

        if ($user->handle === null) {
            throw new HandleRequired;
        }

        $slateGame->loadMissing(['slate.contest', 'slate.games.game', 'game']);
        $slate = $slateGame->slate;

        $isMember = GroupMember::query()
            ->where('group_id', $slate->contest->group_id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            throw new NotGroupMember;
        }

        if (! $slate->isPublished()) {
            throw new InvalidArgumentException("Slate {$slate->id} is not published; there is nothing to wager on yet.");
        }

        if ($slateGame->game->hasKickedOff()) {
            throw new PickLocked;
        }

        $engine = $slate->contest->mode->engine($slate->contest->settings);

        if (! $engine->supportsTallboy()) {
            throw new InvalidArgumentException("Contest {$slate->contest_id} does not take the Tallboy wager.");
        }

        $pick = Pick::query()
            ->where('slate_game_id', $slateGame->id)
            ->where('user_id', $user->id)
            ->first();

        if ($pick === null) {
            throw new InvalidArgumentException('The Tallboy stakes an existing pick; pick a side first.');
        }

        return DB::transaction(fn () => $staked
            ? $this->stake($user, $slate->games->pluck('id')->all(), $pick)
            : $this->pull($user, $pick));
    }

    /**
     * @param  list<int>  $slateGameIds
     *
     * @throws PickLocked|WalletTooLight
     */
    private function stake(User $user, array $slateGameIds, Pick $pick): Pick
    {
        $riding = Pick::query()
            ->where('user_id', $user->id)
            ->whereIn('slate_game_id', $slateGameIds)
            ->where('locked', true)
            ->with('slateGame.game')
            ->first();

        if ($riding !== null && $riding->id === $pick->id) {
            return $pick;
        }

        if ($riding !== null) {
            // A wager whose game has started is IN PLAY: it can neither be
            // moved off nor recovered, so this is the same refusal the
            // kickoff clock gives everywhere else.
            if ($riding->slateGame->game->hasKickedOff()) {
                throw new PickLocked;
            }

            $riding->update(['locked' => false]);
        } else {
            // Only a NEW wager costs — moving one does not, because the
            // credit bought this week's wager rather than this game's.
            $this->wallet->spend($user, 1, GrantWalletEntry::REASON_TALLBOY_WAGER);
        }

        $pick->update(['locked' => true]);

        return $pick;
    }

    private function pull(User $user, Pick $pick): Pick
    {
        if (! $pick->locked) {
            return $pick;
        }

        $pick->update(['locked' => false]);

        // A refund is a NEW POSITIVE ROW. Never an update, never a delete:
        // the history has to keep explaining the balance.
        $this->wallet->handle($user, 0, 1, GrantWalletEntry::REASON_TALLBOY_WAGER);

        return $pick;
    }
}
