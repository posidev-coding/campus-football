<?php

use App\Actions\FollowTeam;
use App\Actions\UnfollowTeam;
use App\Models\Team;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Follow / unfollow a team.
 *
 * Following is what triggers the per-team news fetch, so this is the control
 * that makes a team's News tab fill in.
 */
new class extends Component
{
    public Team $team;

    public function follow(FollowTeam $action): void
    {
        // Guests get sent to log in rather than a silent no-op.
        if (! auth()->check()) {
            $this->redirect(route('login'), navigate: true);

            return;
        }

        $action->handle(auth()->user(), $this->team);

        unset($this->following);
    }

    public function unfollow(UnfollowTeam $action): void
    {
        if (! auth()->check()) {
            return;
        }

        $action->handle(auth()->user(), $this->team);

        unset($this->following);
    }

    #[Computed]
    public function following(): bool
    {
        return auth()->check()
            && auth()->user()->followedTeams()->whereKey($this->team->id)->exists();
    }
}; ?>

<div>
    @if ($this->following)
        <flux:button wire:click="unfollow" size="sm" variant="filled" icon="check">
            Following
        </flux:button>
    @else
        <flux:button wire:click="follow" size="sm" variant="ghost" icon="plus">
            Follow
        </flux:button>
    @endif
</div>
