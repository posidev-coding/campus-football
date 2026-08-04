<?php

use App\Models\Game;
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
 * the point.
 *
 * It is also the only screen in its area, so it carries a heading of its own —
 * everywhere else the section strip already names the screen and a heading
 * would say the same word twice.
 */
new class extends Component
{
    /**
     * A week id, not a week number.
     *
     * Week numbers are not unique within a season — the postseason's "Bowls" is
     * also week 1 — so a number-keyed selector collides them.
     */
    #[Url]
    public ?int $week = null;

    /**
     * '' for an ordinary week, 'bowls' or 'cfp' for the two halves of the
     * postseason, which ESPN publishes as a single 46-game week.
     */
    #[Url]
    public string $bracket = '';

    #[Url]
    public string $scope = '';

    public function mount(CfbCalendar $calendar): void
    {
        if ($this->week === null) {
            $entry = $calendar->defaultWeekEntry($this->year());

            $this->week = $entry['week_id'] ?? null;
            $this->bracket = $entry['bracket'] ?? '';
        }

        /*
         * Top 25 where a poll exists, FBS otherwise. All summer there is no
         * poll — the preseason AP does not land until August — and defaulting
         * to Top 25 anyway meant the filter read "Top 25" while resolving to
         * every FBS team.
         */
        $this->scope = $this->scope ?: Scope::defaultFor($this->year());
    }

    /**
     * Both dimensions move together — a bowls pill and a CFP pill share a week
     * id, so setting the id alone would leave the bracket stale.
     */
    public function selectWeek(int $weekId, string $bracket = ''): void
    {
        $this->week = $weekId;
        $this->bracket = $bracket;
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
            ->when($this->bracket === 'cfp', fn ($q) => $q->playoff())
            ->when($this->bracket === 'bowls', fn ($q) => $q->bowlsOnly())
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
}; ?>

<div
    x-data="{
        /*
         * Day headings stick BELOW the title and week strip, so they need that
         * block's height. Measured rather than hardcoded: the strip's height
         * depends on the font and the title wraps at narrow widths, and a
         * guessed constant leaves either a gap or an overlap.
         */
        sync() {
            const h = this.$refs.chrome?.offsetHeight ?? 0
            this.$el.style.setProperty('--scores-chrome', h + 'px')
        },
    }"
    x-init="sync(); $nextTick(() => sync())"
    x-on:resize.window="sync()"
    class="flex flex-col gap-3"
>
    {{-- Scores is the only screen in its area, so there is no section strip
         above it — which makes this the one heading in the app that is not a
         repeat of the strip. The scope filter sits inline with it rather than
         claiming a row of its own.

         Sticky as a single block: the title and the week strip travel together,
         so the reader always knows which week they are scrolling through. It
         sits under the layout header at `sm`, where that header exists. --}}
    <div
        x-ref="chrome"
        class="sticky top-0 z-20 -mx-4 flex flex-col gap-3 bg-white px-4 pt-1 pb-0 sm:top-14 dark:bg-zinc-950"
    >
        <div class="flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2">
                <flux:heading size="xl" class="truncate">Scoreboard</flux:heading>

                @if ($this->hasLiveGames)
                    <flux:badge color="red" size="sm" class="shrink-0">Live</flux:badge>
                @endif
            </div>

            <x-scope-filter :year="$this->scopeYear" :selected="$scope" class="shrink-0 items-end" />
        </div>

        <x-week-scroller :weeks="$this->weeks" :selected="$week" :bracket="$bracket" :bleed="false" />
    </div>

    {{-- Short-polls our own cache, never ESPN, and only while a game is
         actually in progress. --}}
    <div @if ($this->hasLiveGames) wire:poll.30s.visible @endif class="flex flex-col gap-5">
        @forelse ($this->games as $day => $games)
            <div class="flex flex-col gap-2">
                {{-- Fully opaque, not a translucent blur. A half-transparent
                     heading with game cards sliding under it was genuinely
                     hard to read — backdrop-blur softens the text behind it
                     but does not stop it competing. The negative margin lets
                     the background span the full width so nothing shows
                     through at the edges. --}}
                <flux:subheading
                    class="sticky z-10 -mx-4 bg-white px-4 py-1.5 dark:bg-zinc-950"
                    style="top: var(--scores-chrome, 0px)"
                >
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
                    No {{ App\Support\Scope::label($scope, $this->scopeYear) }} games here.
                    Try another week, or widen the filter to FBS.
                </flux:callout.text>
            </flux:callout>
        @endforelse
    </div>
</div>
