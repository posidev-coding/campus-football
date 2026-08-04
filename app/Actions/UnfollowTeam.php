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
    }
}
