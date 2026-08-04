<?php

use App\Actions\SetFavoriteTeam;
use App\Actions\UnfollowTeam;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Services\CfbCalendar;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Account settings, and the teams a user cares about.
 *
 * Choosing a favourite or following a team dispatches that team's news fetch —
 * see FollowTeam — so this screen is what populates a user's home page and the
 * team's News tab.
 */
new class extends Component
{
    public string $favorite = '';

    public function mount(): void
    {
        $this->favorite = (string) (auth()->user()->favorite_team_id ?? '');
    }

    public function updatedFavorite(SetFavoriteTeam $action): void
    {
        $team = $this->favorite === '' ? null : Team::find((int) $this->favorite);

        $action->handle(auth()->user(), $team);

        unset($this->followed);
    }

    public function unfollow(int $teamId, UnfollowTeam $action): void
    {
        $team = Team::find($teamId);

        if ($team !== null) {
            $action->handle(auth()->user(), $team);
        }

        unset($this->followed);
    }

    /**
     * FBS teams for the current season, so the picker is not 854 entries long.
     *
     * @return list<array{id:int, name:string}>
     */
    #[Computed]
    public function teams(): array
    {
        $year = app(CfbCalendar::class)->scoreboardYear();

        return Cache::remember("account:teams:{$year}", 3600, fn () => Team::query()
            ->whereIn('id', TeamSeason::where('season_year', $year)
                ->where('classification', 'FBS')
                ->pluck('team_id'))
            ->orderBy('display_name')
            ->get(['id', 'display_name'])
            ->map(fn (Team $t) => ['id' => $t->id, 'name' => $t->display_name])
            ->all());
    }

    #[Computed]
    public function followed()
    {
        return auth()->user()
            ->followedTeams()
            ->orderBy('display_name')
            ->get(['teams.id', 'slug', 'display_name', 'short_display_name', 'abbreviation', 'logo', 'logo_dark']);
    }
}; ?>

<div class="flex flex-col gap-6">
    <flux:heading size="xl">Account</flux:heading>

    <flux:card class="flex flex-col gap-3">
        <div class="flex items-center gap-3">
            <flux:avatar :initials="auth()->user()->initials()" />
            <div class="flex flex-col">
                <span class="font-medium">{{ auth()->user()->name }}</span>
                <span class="text-sm text-zinc-500">{{ auth()->user()->email }}</span>
            </div>
        </div>

        <flux:separator />

        <div class="flex items-center justify-between text-sm">
            <span class="text-zinc-500">Trash talk</span>
            <flux:badge size="sm">{{ auth()->user()->trash_talk_intensity->label() }}</flux:badge>
        </div>
    </flux:card>

    <flux:card class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
            <flux:heading size="lg">Your team</flux:heading>
            <flux:subheading>Their news leads your home page.</flux:subheading>
        </div>

        <flux:select wire:model.live="favorite" size="sm" variant="listbox" searchable placeholder="Choose a team">
            <flux:select.option value="">No favourite</flux:select.option>
            @foreach ($this->teams as $team)
                <flux:select.option :value="(string) $team['id']">{{ $team['name'] }}</flux:select.option>
            @endforeach
        </flux:select>
    </flux:card>

    <flux:card class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
            <flux:heading size="lg">Following</flux:heading>
            <flux:subheading>
                Following a team pulls in their news, which is otherwise only a few days deep.
            </flux:subheading>
        </div>

        @forelse ($this->followed as $team)
            <div class="flex items-center gap-2" wire:key="followed-{{ $team->id }}">
                <x-team-link :team="$team" size="sm" class="min-w-0 flex-1" />

                <flux:button
                    wire:click="unfollow({{ $team->id }})"
                    size="xs"
                    variant="ghost"
                    icon="x-mark"
                    aria-label="Unfollow {{ $team->display_name }}"
                />
            </div>
        @empty
            <flux:text class="text-sm text-zinc-500">
                Not following anyone yet. There is a Follow button on every team page.
            </flux:text>
        @endforelse
    </flux:card>
</div>
