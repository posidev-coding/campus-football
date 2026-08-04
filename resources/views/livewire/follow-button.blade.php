<?php

use App\Actions\FollowTeam;
use App\Actions\UnfollowTeam;
use App\Exceptions\FollowLimitReached;
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

    /**
     * Set when the user is already following as many teams as they may.
     */
    public string $error = '';

    public function follow(FollowTeam $action): void
    {
        // Guests get sent to log in rather than a silent no-op.
        if (! auth()->check()) {
            $this->redirect(route('login'), navigate: true);

            return;
        }

        $this->error = '';

        try {
            $action->handle(auth()->user(), $this->team);
        } catch (FollowLimitReached $e) {
            // Said out loud, next to the button they just pressed. A follow
            // that silently does nothing looks like a broken button.
            $this->error = $e->getMessage();

            return;
        }

        unset($this->following);
    }

    public function unfollow(UnfollowTeam $action): void
    {
        if (! auth()->check()) {
            return;
        }

        $this->error = '';

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

<div class="flex flex-col items-end gap-1">
    {{-- Follow is the invitation, so it gets the fill; Following is a state
         you can undo, so it recedes to ghost. This sits on the page surface —
         it was moved out of the accent hero, where no fixed variant could
         hold its contrast against 136 different team colors. --}}
    @if ($this->following)
        <flux:button wire:click="unfollow" size="sm" variant="ghost" icon="check">
            Following
        </flux:button>
    @else
        <flux:button wire:click="follow" size="sm" variant="filled" icon="plus">
            Follow
        </flux:button>
    @endif

    @if ($error)
        <p class="max-w-48 text-right text-micro text-amber-600 dark:text-amber-500">{{ $error }}</p>
    @endif
</div>
