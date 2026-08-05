<?php

namespace App\Actions;

use App\Models\Team;
use App\Models\User;

class UnfollowTeam
{
    /**
     * Drops the follow only. Articles already stored stay: they are shared
     * across every user, and the sync's standing rule is that nothing deletes
     * — ESPN's window is days wide, so an article we drop is one we can never
     * fetch again.
     */
    public function handle(User $user, Team $team): void
    {
        $user->followedTeams()->detach($team->id);

        $this->reindex($user);
    }

    /**
     * Close the gap the removal left, so positions stay 1..N.
     *
     * Left sparse, positions still SORT correctly — but every later writer has
     * to cope with holes: appending reads `max + 1` and would skip a number,
     * and a reorder that assumes contiguity would silently disagree with the
     * database. Cheap to keep tidy at five rows.
     */
    private function reindex(User $user): void
    {
        $user->followedTeams()
            ->get()
            ->each(fn (Team $team, int $index) => $user->followedTeams()
                ->updateExistingPivot($team->id, ['position' => $index + 1]));
    }
}
