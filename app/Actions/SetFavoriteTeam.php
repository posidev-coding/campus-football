<?php

namespace App\Actions;

use App\Models\Team;
use App\Models\User;

/**
 * Choose the one team whose news leads the home page.
 *
 * A favorite implies a follow — nobody picks a favorite team and then expects
 * not to be following it — so this goes through FollowTeam and inherits the
 * news dispatch rather than duplicating it.
 */
class SetFavoriteTeam
{
    public function __construct(private FollowTeam $follow) {}

    public function handle(User $user, ?Team $team): void
    {
        $user->forceFill(['favorite_team_id' => $team?->id])->save();

        if ($team !== null) {
            $this->follow->handle($user, $team);
        }
    }
}
