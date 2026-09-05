<?php

use App\Models\Game;
use App\Models\Team;
use App\Services\CfbCalendar;
use App\Support\GameOrder;
use App\Support\Scope;
use App\Support\SlateDates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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

    /**
     * A `Y-m-d` in the app's timezone, or '' for "work it out".
     *
     * Only the CURRENT week is filtered by date, and only when its games span
     * several days — see `dateTabs()`. A past week is review and a future week
     * is planning; both want the whole week in one scroll, so neither is
     * filtered and neither grows a strip.
     *
     * Deliberately NOT remembered in the session the way `scope` is. Scope is
     * a taste held across visits; a day is triage state whose right answer
     * changes hourly, and serving somebody Thursday on Saturday afternoon is
     * the very bug this was built to fix, just moved into the session.
     *
     * `except: ''` keeps the parameter out of the querystring while it is
     * resolving itself, so simply opening the page adds no URL noise; an
     * explicit tap makes the day shareable. `SlateDates::focus()` is total —
     * an unknown value falls back rather than reaching anything as a bare
     * string, and it never touches SQL — so this needs no `updated` hook to
     * sanitize it.
     */
    #[Url(except: '')]
    public string $date = '';

    public function mount(CfbCalendar $calendar): void
    {
        if ($this->week === null) {
            $entry = $calendar->defaultWeekEntry($this->year());

            $this->week = $entry['week_id'] ?? null;
            $this->bracket = $entry['bracket'] ?? '';
        }

        /*
         * A URL scope wins (a shared link must show what it says), then this
         * AREA's remembered pick, then the season default — Top 25 where a
         * poll exists, FBS otherwise. All summer there is no poll — the
         * preseason AP does not land until August — and defaulting to Top 25
         * anyway meant the filter read "Top 25" while resolving to every FBS
         * team.
         *
         * Scores keeps its own memory. "Whose games are worth watching this
         * Saturday" and League's "whose season am I reading" are different
         * answers held at the same time, so a League visit must not retune
         * this filter.
         */
        $this->scope = $this->scope
            ?: Scope::remembered('scoreboard', $this->year())
            ?? Scope::defaultFor($this->year());
    }

    public function updatedScope(): void
    {
        Scope::remember($this->scope, 'scoreboard');

        // A narrower scope can empty the day the reader was on — Top 25 over a
        // week whose Thursday game was unranked leaves that tab with nothing
        // behind it. Clearing re-resolves rather than stranding them.
        $this->date = '';
    }

    /**
     * Both dimensions move together — a bowls pill and a CFP pill share a week
     * id, so setting the id alone would leave the bracket stale.
     */
    public function selectWeek(int $weekId, string $bracket = ''): void
    {
        $this->week = $weekId;
        $this->bracket = $bracket;

        // Three dimensions now, and a date is the only one that belongs to a
        // single week — Saturday the 27th is not a stop on any other one.
        $this->date = '';
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
     * @return array{pinned: list<array{team: Team, day: string, games: Illuminate\Support\Collection, lead: bool}>, days: Illuminate\Support\Collection, dates: list<array{value: string, label: string}>, selected: string|null}
     */
    #[Computed]
    public function slate(): array
    {
        /*
         * Grouped by day, then each day stratified live → upcoming → final.
         *
         * The query orders by `kickoff_at` alone, which is chronology rather
         * than urgency — so a game in the fourth quarter sat underneath every
         * noon final that had already been decided. `GameOrder::liveFirst()`
         * lifts the bands and leaves the kickoff order untouched inside each
         * one, so this is the existing ordering stratified, not replaced.
         *
         * Applied HERE rather than in `scopedGames()` so both halves of the
         * screen inherit it: the pinned followed-team groups run through the
         * same closure, and a followed team playing live should float inside
         * its own block for exactly the reason it should in the day groups.
         *
         * It is also why this is not an `orderByRaw` in the query. The band
         * has to be nested INSIDE the day, and the day is an Eastern-time
         * format string — asking SQL for it means CONVERT_TZ, which returns
         * NULL where the timezone tables were never loaded and would collapse
         * the whole ordering without saying so.
         */
        $byDay = fn ($games) => $games
            ->groupBy(fn (Game $game) => $game->kickoff_at->setTimezone(config('cfb.timezone'))->format('l, M j'))
            ->map(fn ($dayGames) => GameOrder::liveFirst($dayGames));

        $games = $this->scopedGames();

        /*
         * The date filter is applied to the WHOLE set, before the pinned split
         * below, so both halves of the screen agree about which day is on. A
         * pinned Thursday game left standing above a Saturday-filtered list
         * would contradict the tab the reader just pressed.
         *
         * The tabs are built from the unfiltered set, because the strip has to
         * name every day the week holds even though only one of them renders.
         */
        $dates = $this->dateTabs($games);
        $selected = SlateDates::focus($dates, $games, $this->date);

        if ($selected !== null) {
            $games = $games->filter(fn (Game $game) => SlateDates::key($game) === $selected);
        }

        $teams = $this->pinnedTeams();

        if ($teams->isEmpty()) {
            return ['pinned' => [], 'days' => $byDay($games), 'dates' => $dates, 'selected' => $selected];
        }

        $pinned = [];
        $claimed = [];

        /*
         * Walked in the user's own order, and each game is claimed by the
         * FIRST team that wants it. That is what keeps a game between two
         * followed teams appearing once, under the one they ranked higher,
         * rather than twice under both.
         */
        foreach ($teams as $index => $team) {
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
                    'lead' => $index === 0,
                ];
            }
        }

        return [
            'pinned' => $pinned,
            'days' => $byDay($games->reject(fn (Game $game) => isset($claimed[$game->id]))),
            'dates' => $dates,
            'selected' => $selected,
        ];
    }

    /**
     * Is NOW inside the week the reader is looking at?
     *
     * Deliberately stricter than "the week the app would have opened on".
     * `defaultWeekEntry()` falls back to the NEAREST week when we are between
     * weeks or out of season, so in August it answers with a week that is a
     * fortnight away — and a week we are not in is one a reader is planning or
     * reviewing, not triaging. Filtering it would hide days from somebody who
     * came to see the whole shape of a week.
     *
     * `week()` takes no argument on purpose: that path is cached AND memoized
     * per request, where `week($at)` is neither and would add a query to every
     * render, including every 30-second poll on a live Saturday.
     *
     * The bracket still comes from `defaultWeekEntry()`, because both halves
     * of the postseason share one week id and `week()` cannot tell them apart.
     */
    private function isCurrentWeek(): bool
    {
        $calendar = app(CfbCalendar::class);
        $current = $calendar->week();

        if ($current === null || $current->id !== $this->week) {
            return false;
        }

        return ($calendar->defaultWeekEntry($this->year())['bracket'] ?? '') === $this->bracket;
    }

    /**
     * The strip of days this week's games actually land on — or none at all,
     * which is the signal to render the whole week the way we always have.
     *
     * Empty in three cases, each deliberate: a week that is not the current
     * one, a week whose games all fall on one day (a strip of one is not a
     * choice), and a week spanning more days than a strip can hold at 390px.
     *
     * @param  Illuminate\Support\Collection<int, Game>  $games
     * @return list<array{value: string, label: string}>
     */
    private function dateTabs($games): array
    {
        if (! $this->isCurrentWeek()) {
            return [];
        }

        $index = SlateDates::index($games);

        // A strip of one is not a choice, and a strip of twenty-one is bowl
        // season, which no row can hold — both fall back to the whole week.
        return count($index) >= 2 && count($index) <= SlateDates::MAX_TABS ? $index : [];
    }

    /**
     * The viewer's teams, in the order they set on Account.
     *
     * One query and no reconciliation. This used to sort a favorite to the
     * front and UNION it in when it was somehow not followed — a guard that
     * existed only because a favorite lived outside the follow list and could
     * disagree with it. An ordered list cannot.
     *
     * @return Illuminate\Support\Collection<int, Team>
     */
    private function pinnedTeams()
    {
        $user = auth()->user();

        if ($user === null) {
            return collect();
        }

        return $user->followedTeams()
            ->get(['teams.id', 'location', 'display_name', 'short_display_name']);
    }

    #[Computed]
    public function games()
    {
        return $this->slate()['days'];
    }

    /** @return list<array{team: Team, day: string, games: Illuminate\Support\Collection, lead: bool}> */
    #[Computed]
    public function pinned(): array
    {
        return $this->slate()['pinned'];
    }

    /**
     * The day strip, or [] where this week does not get one.
     *
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function dates(): array
    {
        return $this->slate()['dates'];
    }

    /** The day being shown, or null where the whole week is. */
    #[Computed]
    public function selectedDate(): ?string
    {
        return $this->slate()['selected'];
    }

    /**
     * The active scroller entry's kickoff bounds, when it carries them —
     * only a split opening week's segments do.
     *
     * @return array{0: int, 1: int}|null
     */
    private function activeBounds(): ?array
    {
        foreach ($this->weeks as $entry) {
            if ($entry['week_id'] === $this->week && ($entry['bracket'] ?? '') === $this->bracket) {
                return $entry['bounds'] ?? null;
            }
        }

        return null;
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
            // The split opening week: its two scroller stops share one
            // week_id, and the entry's kickoff bounds are what tell the
            // 8/29 card from the 9/5 one.
            ->when(($bounds = $this->activeBounds()) !== null, fn ($q) => $q
                ->where('kickoff_at', '>=', CarbonImmutable::createFromTimestamp($bounds[0]))
                ->where('kickoff_at', '<', CarbonImmutable::createFromTimestamp($bounds[1])))
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
        /*
         * 15s: every scoreboard viewer's 30s poll asked this EXISTS
         * fresh, and the answer flips a handful of times a Saturday. The
         * sync's own guard stays uncached — a scheduler minute must read
         * the real row.
         */
        return Cache::remember('scoreboard:has-live', 15, fn () => Game::query()->inProgress()->exists());
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

         The offset is shared: `--chrome-offset` is the header's MEASURED
         height — the app bar, its border, the standalone status-bar inset,
         and the section strip when the area carries one. Scores has no
         strip, so here it resolves to the same number the old summed
         `--header-offset` gave; on Picks and League it is bigger, which is
         the bug it exists to fix. Sticking at a flat `top-14` once left the
         block one pixel of travel, which is small but is still the drift
         this is meant to remove.

         Still `sticky`, not `fixed`. With zero travel the two are visually
         identical, but `fixed` would take the block out of flow and drop the
         whole page underneath it — needing a spacer the exact height of a
         block whose height is variable, which is the very thing
         `--scores-chrome` exists to measure. --}}
    <div
        x-ref="chrome"
        class="sticky top-[var(--chrome-offset)] z-30 -mx-4 -mt-5 flex flex-col gap-3 bg-white px-4 pt-3 pb-0 dark:bg-zinc-950"
    >
        <div class="flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2">
                {{-- Scores has no section strip, so this heading is the app's
                     one non-redundant one — and below `sm`, where there is no
                     header, the mark beside it is where a sports site puts
                     its brand. From `sm` the header's lockup is on screen, so
                     the mark retires rather than branding the page twice. The
                     chrome's height is measured by the ResizeObserver above,
                     so `--scores-chrome` follows this without a constant to
                     keep in step. --}}
                <x-brand.mark class="size-6 shrink-0 sm:hidden" />

                <flux:heading size="xl" class="truncate">Scoreboard</flux:heading>

                @if ($this->hasLiveGames)
                    <flux:badge color="red" size="sm" class="shrink-0">Live</flux:badge>
                @endif
            </div>

            <x-scope-filter :year="$this->scopeYear" :selected="$scope" class="shrink-0 items-end" />
        </div>

        <x-week-scroller :weeks="$this->weeks" :selected="$week" :bracket="$bracket" :bleed="false" />

        {{-- The day strip lives INSIDE the chrome rather than over the content,
             and that placement is load-bearing. `--scores-chrome` is measured
             from this block's height by the ResizeObserver above, so the day
             headings below go on sticking to the bottom of the chrome with no
             constant to keep in step. A strip that stuck on its own would land
             at the same offset as the first pinned heading and the two would
             sit on top of each other.

             Only the current week gets one, and only when its games span
             several days — see `dateTabs()`. --}}
        @if ($this->dates !== [])
            <x-gutter-tabs
                variant="fill"
                :items="$this->dates"
                :selected="$this->selectedDate"
                model="date"
                label="Day"
                key-prefix="scoreboard-date"
                class="mb-3"
            />
        @endif
    </div>

    {{-- Short-polls our own cache, never ESPN, and only while a game is
         actually in progress. Dims while the chrome navigates — a week or
         scope change was a dead interval with no acknowledgment at all. --}}
    <div
        @if ($this->hasLiveGames) wire:poll.30s.visible @endif
        wire:loading.class="opacity-60 pointer-events-none"
        wire:target="week, scope, bracket, date"
        class="flex flex-col gap-5 motion-safe:transition-opacity"
    >
        {{-- The viewer's teams first, in the order they set on Account. These
             games were lifted OUT of the day groups below, so they appear
             once, not twice — floating a game is moving it, not copying it.

             There is no scope check here on purpose. These come from the same
             filtered set as everything else, so a team the scope excluded never
             reaches this loop. --}}
        @foreach ($this->pinned as $group)
            <x-scoreboard-day
                :heading="$group['team']->placeName()"
                :meta="$group['day']"
                :games="$group['games']"
                pinned
                :lead="$group['lead']"
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
