<?php

use App\Models\Game;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Support\Scope;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The scoreboard reads the database and cache only. It never calls ESPN.
 *
 * v3 called ESPN inside render(), so a `wire:poll` on a live game issued one
 * upstream request per viewer per 15 seconds. Here the scheduled live tier does
 * one request a minute for everybody, and this component just reads what it
 * wrote — so viewer count has no effect on upstream load at all.
 *
 * There is deliberately NO season selector. This is a "what is on now" screen;
 * comparing years belongs on Standings, Rankings, Stats and Leaders, where it is
 * the point. The season is whichever one the calendar says has results.
 */
new class extends Component
{
    /**
     * A week id, not a week number.
     *
     * Week numbers are not unique within a season — the postseason's "Bowls" is
     * also week 1 — so a number-keyed selector collides them and makes the bowl
     * slate unreachable.
     */
    #[Url]
    public ?int $week = null;

    #[Url]
    public string $scope = Scope::TOP_25;

    public function mount(CfbCalendar $calendar): void
    {
        $this->week ??= $calendar->defaultWeekId($this->year());
    }

    /**
     * The season we are in or heading into, provided it has a schedule.
     *
     * NOT resultsYear(): that is the latest season with games PLAYED, which in
     * August is last season — so the scoreboard would open on bowl games from
     * eight months ago instead of the upcoming week 1.
     */
    private function year(): int
    {
        return app(CfbCalendar::class)->scoreboardYear();
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function weeks(): array
    {
        return app(CfbCalendar::class)->weekReleases($this->year());
    }

    #[Computed]
    public function scopeYear(): int
    {
        return $this->year();
    }

    #[Computed]
    public function games()
    {
        if ($this->week === null) {
            return collect();
        }

        $query = Game::query()
            // slug is the Team route key; omitting it from a constrained eager load
            // breaks route() in a way that looks like a null relation.
            ->with([
                'homeTeam:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark',
                'awayTeam:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark',
                'venue:id,name',
                'odds',
            ])
            ->where('week_id', $this->week)
            ->orderBy('kickoff_at');

        $teamIds = Scope::teamIds($this->scope, $this->year());

        // Null means "do not filter" and an empty array means "filter to
        // nothing". Treating them the same would show every game for a scope
        // that has no members.
        if ($teamIds !== null) {
            $query->where(fn ($q) => $q
                ->whereIn('home_team_id', $teamIds)
                ->orWhereIn('away_team_id', $teamIds));
        }

        return $query->get()->groupBy(
            fn (Game $game) => $game->kickoff_at->setTimezone(config('cfb.timezone'))->format('l, M j')
        );
    }

    /**
     * Drives the poll interval. Only polls while something is actually live,
     * so an idle scoreboard costs nothing.
     */
    #[Computed]
    public function hasLiveGames(): bool
    {
        return Game::query()->inProgress()->exists();
    }

    #[Computed]
    public function weekLabel(): ?string
    {
        return Week::find($this->week)?->name;
    }
}; ?>

<div class="flex flex-col gap-4">
    <div class="flex items-start justify-between gap-3">
        <x-scope-filter title="Scores" :year="$this->scopeYear" :selected="$scope" />

        @if ($this->hasLiveGames)
            <flux:badge color="red" size="sm" class="mt-1 shrink-0">Live</flux:badge>
        @endif
    </div>

    <x-week-scroller :weeks="$this->weeks" :selected="$week" />

    {{-- Short-polls our own cache, never ESPN, and only while a game is
         actually in progress. --}}
    <div @if ($this->hasLiveGames) wire:poll.30s.visible @endif class="flex flex-col gap-5">
        @forelse ($this->games as $day => $games)
            <div class="flex flex-col gap-2">
                <flux:subheading class="sticky top-14 z-10 bg-white/90 py-1 backdrop-blur dark:bg-zinc-950/90">
                    {{ $day }}
                </flux:subheading>

                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($games as $game)
                        <x-game-card :game="$game" wire:key="game-{{ $game->id }}" />
                    @endforeach
                </div>
            </div>
        @empty
            <flux:callout icon="calendar-days">
                <flux:callout.heading>Nothing on the slate</flux:callout.heading>
                <flux:callout.text>
                    No {{ App\Support\Scope::label($scope, $this->scopeYear) }} games
                    {{ $this->weekLabel ? 'in '.$this->weekLabel : 'this week' }}.
                    Try another week, or widen the filter to FBS.
                </flux:callout.text>
            </flux:callout>
        @endforelse
    </div>
</div>
