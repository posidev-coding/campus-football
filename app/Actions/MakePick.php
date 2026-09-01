<?php

namespace App\Actions;

use App\Enums\UxSignal;
use App\Exceptions\HandleRequired;
use App\Exceptions\NotGroupMember;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\PickLocked;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
 * cap, so changing picks all week pays nothing twice. A NEW seat is also
 * where the participation milestones are counted; see milestones() below.
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

        $entry = SlateEntry::query()->firstOrCreate(['slate_id' => $slate->id, 'user_id' => $user->id]);

        // The moment somebody is really playing. Keyed on the entry being
        // NEW, so changing picks all week counts once — the same fact the XP
        // grant below rides, and the reason it is measured here rather than
        // on the screen that shows the sheet.
        if ($entry->wasRecentlyCreated) {
            app(RecordUxEvent::class)->handle(UxSignal::FirstPickMade);
        }

        $this->wallet->handle(
            $user,
            GrantWalletEntry::PICKEM_ENTERED_XP,
            0,
            GrantWalletEntry::REASON_PICKEM_ENTERED,
            "slate:{$slate->id}:in",
        );

        if ($entry->wasRecentlyCreated) {
            $this->milestones($user);
        }

        return $pick;
    }

    /**
     * The participation milestones, counted on a NEW seat only.
     *
     * Counted in SATURDAYS, not in entries: somebody in four groups seats
     * four slates on one Saturday, and paying that as four weeks would hand
     * a multi-league reader the ten-week milestone before Halloween. The
     * milestone is about showing up week after week, so the week is what it
     * counts — the same reason every read in this phase keys on the slate's
     * own Saturday rather than on an ESPN week that can hold two of them.
     *
     * Every grant is keyed, so this is safe to re-enter and safe to run on a
     * back-dated seat: the fifth Saturday pays once, ever.
     */
    private function milestones(User $user): void
    {
        $this->wallet->handle(
            $user,
            0,
            GrantWalletEntry::FIRST_SLATE_CREDITS,
            GrantWalletEntry::REASON_FIRST_SLATE,
            GrantWalletEntry::REASON_FIRST_SLATE,
        );

        $saturdays = SlateEntry::query()
            ->where('slate_entries.user_id', $user->id)
            ->join('slates', 'slates.id', '=', 'slate_entries.slate_id')
            ->distinct()
            ->count(DB::raw('slates.saturday'));

        foreach (GrantWalletEntry::WEEKS_ENTERED_CREDITS as $weeks => $credits) {
            if ($saturdays >= $weeks) {
                $this->wallet->handle(
                    $user,
                    0,
                    $credits,
                    GrantWalletEntry::REASON_WEEKS_ENTERED,
                    "weeks:{$weeks}",
                );
            }
        }
    }
}
