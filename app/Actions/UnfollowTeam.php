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

        /*
         * The favorite is one OF the followed teams, so unfollowing it has to
         * clear it too. Left set, `favorite_team_id` would point at a team the
         * user no longer follows — their news would still lead the home page
         * and their games would still float to the top of the scoreboard, with
         * nothing on the account screen to explain why or turn it off.
         */
        if ($user->favorite_team_id === $team->id) {
            $user->forceFill(['favorite_team_id' => null])->save();
        }
    }
}
