<?php

namespace App\Livewire\Concerns;

use App\Actions\FollowTeam;
use App\Exceptions\FollowLimitReached;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamGlance;
use App\Support\Voice;
use Livewire\Attributes\Computed;

/**
 * A team picker: search FBS, tap, follow.
 *
 * Shared by Home's quick-add slot and the onboarding overlay, which are the
 * same interaction on two surfaces. A trait rather than a child component
 * because both hosts need the RESULT in their own state — Home re-renders its
 * glance cards, onboarding advances its own screen — and an event round trip
 * to tell a parent what its child just did is more machinery than the fifty
 * lines it would save.
 *
 * Livewire single-file components are anonymous classes, which can `use` a
 * trait like any other; `App\Livewire\Actions` is the existing precedent for
 * this namespace.
 *
 * Hosts must implement `followedTeams()` (a collection of Team) and are
 * responsible for busting their own computed caches in `afterTeamAdded()`.
 */
trait PicksTeams
{
    public string $teamQuery = '';

    public string $followError = '';

    /**
     * Follow a team, appending it to the end of the user's ordered list.
     *
     * There is no "first team becomes the favorite" branch anymore: the list
     * IS the ranking, so being added first is exactly what being the favorite
     * used to mean.
     */
    public function addTeam(int $teamId, FollowTeam $follow): void
    {
        $user = auth()->user();
        $team = Team::find($teamId);

        $this->followError = '';

        if ($user === null || $team === null) {
            return;
        }

        try {
            $follow->handle($user, $team);
        } catch (FollowLimitReached $e) {
            // Left in place on failure so the user can see what they reached
            // for beside the reason it did not land.
            $this->followError = Voice::line('follow.limit', ['max' => $e->limit]);

            return;
        }

        $this->teamQuery = '';

        unset($this->followable, $this->teamMatches, $this->canFollowMore);

        $this->afterTeamAdded($team);
    }

    /** Hook for the host to refresh whatever it renders from the follow list. */
    protected function afterTeamAdded(Team $team): void
    {
        //
    }

    /** Room for another team. */
    #[Computed]
    public function canFollowMore(): bool
    {
        return auth()->check()
            && $this->followedTeams->count() < User::MAX_FOLLOWED_TEAMS;
    }

    /**
     * FBS teams they are not already following.
     *
     * @return list<array{id:int, name:string}>
     */
    #[Computed]
    public function followable(): array
    {
        $already = $this->followedTeams->pluck('id')->all();

        return collect(TeamGlance::fbsTeams())
            ->reject(fn (array $team) => in_array($team['id'], $already, true))
            ->values()
            ->all();
    }

    /**
     * Matches for the typed query, capped — an unbounded list inside a
     * scroll-snap card would push the whole slate off the screen.
     *
     * @return list<array{id:int, name:string}>
     */
    #[Computed]
    public function teamMatches(): array
    {
        $query = trim($this->teamQuery);

        if ($query === '') {
            return [];
        }

        return collect($this->followable)
            ->filter(fn (array $team) => str_contains(mb_strtolower($team['name']), mb_strtolower($query)))
            ->take(5)
            ->values()
            ->all();
    }
}
