<?php

use App\Models\Game;
use App\Models\Team;
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

    /**
     * The week's slate, split into the viewer's teams and everything else.
     *
     * Both halves come out of ONE already-filtered result set, and that is the
     * whole design. A followed team is floated by being lifted out of the games
     * the scope already admitted — never by a second query that fetches it
     * separately.
     *
     * That is what makes the rule hold without a rule: pick Top 25 while your
     * team is unranked and their game was never in the set to be lifted out of,
     * so it does not appear. Fetching it separately and then re-checking the
     * scope would be the same behaviour held together by a condition somebody
     * has to remember to keep in step with `Scope`.
     *
     * @return array{pinned: list<array{team: Team, day: string, games: Illuminate\Support\Collection, favorite: bool}>, days: Illuminate\Support\Collection}
     */
    #[Computed]
    public function slate(): array
    {
        $byDay = fn ($games) => $games->groupBy(
            fn (Game $game) => $game->kickoff_at->setTimezone(config('cfb.timezone'))->format('l, M j')
        );

        $games = $this->scopedGames();
        $teams = $this->pinnedTeams();
        $favorite = auth()->user()?->favorite_team_id;

        if ($teams->isEmpty()) {
            return ['pinned' => [], 'days' => $byDay($games)];
        }

        $pinned = [];
        $claimed = [];

        /*
         * Walked in priority order — favorite first — and each game is claimed
         * by the FIRST team that wants it. That is what keeps a game between
         * two followed teams appearing once, under the one the viewer cares
         * about more, rather than twice under both.
         */
        foreach ($teams as $team) {
            $theirs = $games->filter(fn (Game $game) => ! isset($claimed[$game->id])
                && ($game->home_team_id === $team->id || $game->away_team_id === $team->id));

            foreach ($theirs as $game) {
                $claimed[$game->id] = true;
            }

            // Grouped by day as well, so a pinned card keeps its date. Lifting
            // it out of the chronology is the point, but losing the date with
            // it is not — "3:30pm" alone does not say which day.
            foreach ($byDay($theirs) as $day => $dayGames) {
                $pinned[] = [
                    'team' => $team,
                    'day' => $day,
                    'games' => $dayGames,
                    'favorite' => $team->id === $favorite,
                ];
            }
        }

        return [
            'pinned' => $pinned,
            'days' => $byDay($games->reject(fn (Game $game) => isset($claimed[$game->id]))),
        ];
    }

    /**
     * The viewer's teams, favorite first, then everyone else they follow.
     *
     * The favorite leads because it is the one team they would look for before
     * any other; the rest follow in the order they were followed. Setting a
     * favorite also follows it (see SetFavoriteTeam), but this unions the two
     * anyway rather than trusting that invariant to hold for every row that
     * already exists.
     *
     * @return Illuminate\Support\Collection<int, Team>
     */
    private function pinnedTeams()
    {
        $user = auth()->user();

        if ($user === null) {
            return collect();
        }

        $columns = ['teams.id', 'location', 'display_name', 'short_display_name'];

        $followed = $user->followedTeams()
            ->orderByPivot('created_at')
            // Deterministic tiebreak. Several follows written in the same
            // second — which is exactly what onboarding will do — otherwise
            // come back in whatever order MySQL feels like, and the pinned
            // block reshuffles itself between page loads.
            ->orderBy('teams.display_name')
            ->get($columns);

        $favorite = $user->favorite_team_id;

        // Union, not just a sort. Setting a favorite follows it too, but a row
        // written before that was true — or by a future caller that skips the
        // action — would otherwise drop the viewer's own team off the top of
        // their scoreboard, which is the one thing this feature exists to do.
        if ($favorite !== null && ! $followed->contains('id', $favorite)) {
            $followed->push(Team::select($columns)->find($favorite));
        }

        return $followed
            ->filter()
            // PHP's sort is stable, so the followed order survives inside the
            // second group.
            ->sortBy(fn (Team $team) => $team->id === $favorite ? 0 : 1)
            ->values();
    }

    #[Computed]
    public function games()
    {
        return $this->slate()['days'];
    }

    /** @return list<array{team: Team, day: string, games: Illuminate\Support\Collection, favorite: bool}> */
    #[Computed]
    public function pinned(): array
    {
        return $this->slate()['pinned'];
    }

    private function scopedGames()
    {
        if ($this->week === null) {
            return collect();
        }

        $query = Game::query()
            // slug is the Team route key; omitting it from a constrained eager load
            // breaks route() in a way that looks like a null relation.
            ->with([
                'homeTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                'awayTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
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
                ->orWhereIn('away_team_id', $teamIds)
                /*
                 * A fixture whose teams are not announced yet cannot be
                 * excluded on the basis of its teams. Every bowl and playoff
                 * game is published as TBD-vs-TBD months ahead, so filtering
                 * them out on "does not involve an FBS team" would empty the
                 * entire postseason slate until December — which is precisely
                 * the stretch when knowing the date and venue is useful.
                 */
                ->orWhere(fn ($tbd) => $tbd
                    ->whereNull('home_team_id')
                    ->whereNull('away_team_id')));
        }

        return $query->get();
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
         *
         * Written to the DOCUMENT element, not to this component's root. The
         * server HTML carries no `style` attribute here, so Livewire's morph
         * treats an inline one as drift and strips it — picking a different
         * week wiped the variable, `top` fell back to 0, and every day heading
         * stuck underneath the chrome instead of below it. Livewire never
         * morphs <html>.
         */
        sync() {
            const chrome = this.$refs.chrome

            if (! chrome) return

            /*
             * The chrome's own sticky offset counts too. It is `top-0` at base
             * but `sm:top-14`, clearing the layout header that only exists from
             * `sm` up — so its resting bottom edge is its height PLUS that
             * offset. Measuring height alone put every day heading 56px too
             * high from `sm` up, which hid them behind the chrome rather than
             * parking them below it.
             */
            const offset = parseFloat(getComputedStyle(chrome).top) || 0

            document.documentElement.style.setProperty(
                '--scores-chrome', (chrome.offsetHeight + offset) + 'px'
            )
        },
    }"
    {{--
        Both listeners earn their place. The observer catches height changes a
        window resize never sees — the webfont swapping in, the title wrapping,
        the strip gaining or losing the postseason pills on a week change. The
        resize handler catches the reverse: crossing `sm` changes the chrome's
        `top` from 0 to 56px without changing its height at all.
    --}}
    x-init="
        sync()
        new ResizeObserver(() => sync()).observe($refs.chrome)
    "
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
    {{-- `-mt-5` cancels the layout container's `py-5`, exactly as `-mx-4`
         cancels its `px-4`. Without it the block sat 20px down the page and
         travelled that distance before sticking, which reads as the title
         drifting upward on the first flick of a scroll.

         The breathing room moves INSIDE the block as `pt-3`, so it belongs to
         the chrome and travels with it rather than scrolling away. Net space
         above the title goes from 24px to 12px.

         The `sm` offset is 14 spacing units PLUS ONE PIXEL, because that is the
         header's real height: `h-14` plus its own `border-b`. Sticking at a
         flat `top-14` left the block one pixel of travel, which is small but
         is still the drift this is meant to remove.

         Still `sticky`, not `fixed`. With zero travel the two are visually
         identical, but `fixed` would take the block out of flow and drop the
         whole page underneath it — needing a spacer the exact height of a
         block whose height is variable, which is the very thing
         `--scores-chrome` exists to measure. --}}
    <div
        x-ref="chrome"
        class="sticky top-0 z-30 -mx-4 -mt-5 flex flex-col gap-3 bg-white px-4 pt-3 pb-0 sm:top-[calc(var(--spacing)*14+1px)] dark:bg-zinc-950"
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
        {{-- The viewer's teams first, favorite at the top. These games were
             lifted OUT of the day groups below, so they appear once, not twice
             — floating a game is moving it, not copying it.

             There is no scope check here on purpose. These come from the same
             filtered set as everything else, so a team the scope excluded never
             reaches this loop. --}}
        @foreach ($this->pinned as $group)
            <x-scoreboard-day
                :heading="$group['team']->placeName()"
                :meta="$group['day']"
                :games="$group['games']"
                pinned
                :favorite="$group['favorite']"
                wire:key="pinned-{{ $group['team']->id }}-{{ $loop->index }}"
            />
        @endforeach

        @foreach ($this->games as $day => $games)
            <x-scoreboard-day :heading="$day" :games="$games" wire:key="day-{{ $day }}" />
        @endforeach

        {{-- Checks BOTH halves. A week where the only games in scope belong to
             the viewer's own teams leaves the day groups empty, and keying the
             empty state on those alone would print "Nothing on the slate"
             directly above their games. --}}
        @if ($this->pinned === [] && $this->games->isEmpty())
            <flux:callout icon="calendar-days">
                <flux:callout.heading>Nothing on the slate</flux:callout.heading>
                <flux:callout.text>
                    No {{ App\Support\Scope::label($scope, $this->scopeYear) }} games here.
                    Try another week, or widen the filter to FBS.
                </flux:callout.text>
            </flux:callout>
        @endif
    </div>
</div>
