<?php

use App\Actions\FollowTeam;
use App\Actions\UnfollowTeam;
use App\Exceptions\FollowLimitReached;
use App\Models\Team;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Follow / unfollow a team, styled for the accent hero it lives on.
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
    {{-- Hand-rolled rather than flux:button: this sits on the team's accent,
         and no fixed variant holds its contrast against 136 different colors.
         Follow INVERTS the hero — the hero's text color as the surface, the
         accent as the label — so the pairing is always the same one the
         header already proved readable. Following recedes to an outline in
         the hero's own text color. --}}
    @if ($this->following)
        <button
            type="button"
            wire:click="unfollow"
            wire:loading.attr="disabled"
            class="flex h-8 shrink-0 items-center gap-1.5 rounded-md px-3 text-sm font-medium ring-1 ring-current/50 transition-opacity hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current disabled:opacity-50"
        >
            <flux:icon name="check" variant="micro" />
            Following
        </button>
    @else
        {{-- `team-invert` in CSS rather than an inline style: an inline style
             cannot be dark-gated, and in dark mode this is simply a light
             neutral button on the dark page. --}}
        <button
            type="button"
            wire:click="follow"
            wire:loading.attr="disabled"
            class="team-invert flex h-8 shrink-0 items-center gap-1.5 rounded-md px-3 text-sm font-semibold shadow-sm transition-opacity hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current disabled:opacity-50"
        >
            <flux:icon name="plus" variant="micro" />
            Follow
        </button>
    @endif

    @if ($error)
        {{-- Current color, not a fixed amber: this renders on an arbitrary
             team accent, and the hero's text color is the one pairing already
             proven readable there. --}}
        <p class="max-w-48 text-right text-micro opacity-90">{{ $error }}</p>
    @endif
</div>
