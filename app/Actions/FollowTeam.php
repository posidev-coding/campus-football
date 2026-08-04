<?php

namespace App\Actions;

use App\Exceptions\FollowLimitReached;
use App\Jobs\SyncTeamNews;
use App\Models\Team;
use App\Models\User;

/**
 * Follow a team.
 *
 * An action rather than a line in a Livewire component because following
 * happens from several places already — the team page, the account screen, and
 * eventually onboarding and the API — and the news dispatch has to ride along
 * with every one of them. A caller that forgets it produces a follower with an
 * empty news tab, which reads as a broken feature rather than a missing call.
 */
class FollowTeam
{
    /**
     * @throws FollowLimitReached when the user already follows the maximum
     */
    public function handle(User $user, Team $team): void
    {
        // Checked BEFORE the limit, not after. Re-following a team you already
        // follow is a no-op, so it must not be rejected for being over the cap
        // — a user sitting at exactly the limit would otherwise be unable to
        // press follow on a team they are already following.
        if ($user->followedTeams()->whereKey($team->id)->exists()) {
            return;
        }

        if ($user->followedTeams()->count() >= User::MAX_FOLLOWED_TEAMS) {
            throw new FollowLimitReached;
        }

        // syncWithoutDetaching, so following twice is a no-op rather than a
        // unique-constraint violation.
        $user->followedTeams()->syncWithoutDetaching([$team->id]);

        $this->warmNews($team);
    }

    /**
     * Pull the team's own feed in the background.
     *
     * This is the moment worth spending a request on: ESPN's per-team feed
     * carries stories the national feed never had — measured live, ~20-25
     * articles per team we did not already hold — so a new follower gets a
     * populated news tab instead of an empty one.
     *
     * The job is unique per team, so a popular team gaining many followers at
     * once still costs a single fetch.
     */
    public function warmNews(Team $team): void
    {
        SyncTeamNews::dispatch($team->id);
    }
}
