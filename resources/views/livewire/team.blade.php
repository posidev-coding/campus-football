<?php

use App\Jobs\SyncTeamNews;
use App\Models\Article;
use App\Models\AthleteTeamSeason;
use App\Models\Game;
use App\Models\Recruit;
use App\Models\RecruitSchool;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamLeader;
use App\Models\TeamSeasonStat;
use App\Services\CfbCalendar;
use App\Support\RecruitingClasses;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A team's whole season on one page: record, leaders, stats, schedule, roster.
 *
 * The team's own colour drives the page accent via the --team-accent custom
 * property, so this reads as *that team's* page rather than a generic template.
 */
new class extends Component
{
    public Team $team;

    #[Url]
    public ?int $year = null;

    /** Schedule leads: it is what someone opening a team page came to see. */
    #[Url]
    public string $tab = 'schedule';

    /**
     * Within Stats: 'team' (how good is this team) or 'players' (who on it is
     * good). Team leads, matching the League Stats screen's own sub-toggle.
     */
    #[Url]
    public string $statsView = 'team';

    /**
     * Within Roster: a `position_group`, or '' for the whole squad.
     *
     * Defaults to all — a roster tab that opened on offense would hide two
     * thirds of the team from someone who came to look at the team.
     */
    #[Url]
    public string $rosterGroup = '';

    /**
     * Opens on the season we are IN or heading into, not the last one played.
     *
     * `resultsYear()` is the wrong question here and the difference only shows
     * up in the summer: from February to late August the upcoming season is
     * fully scheduled but unplayed, so a team page defaulting to results
     * showed last year's finished schedule and — because `latestYear()` fed
     * the select the same value — did not even OFFER the current year.
     */
    public function mount(Team $team, CfbCalendar $calendar): void
    {
        $this->team = $team;
        $this->year ??= $calendar->scoreboardYear();
    }

    #[Computed]
    public function latestYear(): int
    {
        return app(CfbCalendar::class)->scoreboardYear();
    }

    /**
     * Which season's statistics we can actually show.
     *
     * Same shape as `rosterYear()` below, and for the same reason: stats only
     * exist once games have been played, so a page opened on the upcoming
     * season has none. Showing last season's numbers under a label beats an
     * empty screen — nobody visiting in August wants to be told there are no
     * stats for a season that has not started.
     */
    #[Computed]
    public function statsYear(): int
    {
        $hasStats = TeamSeasonStat::where('team_id', $this->team->id)
            ->where('season_year', $this->year)
            ->exists();

        if ($hasStats) {
            return $this->year;
        }

        return (int) (TeamSeasonStat::where('team_id', $this->team->id)->max('season_year')
            ?? TeamLeader::where('team_id', $this->team->id)->max('season_year')
            ?? $this->year);
    }

    #[Computed]
    public function seasonRow(): ?\App\Models\TeamSeason
    {
        return $this->team->seasonFor($this->year)->with('conference')->first();
    }

    /**
     * The team's own conference row, not whichever of its several ESPN
     * standings rows came back first.
     *
     * "(4-4)" beside the word SEC is a claim about the SEC, and ESPN files a
     * team under its division group as well — so an unscoped `first()` was
     * reading a conference record off a row that need not be the conference's.
     * The two agree in every season we hold, which is exactly why it would
     * have gone unnoticed the season they stopped.
     */
    #[Computed]
    public function standing(): ?Standing
    {
        return Standing::fromEspn()
            ->where('season_year', $this->year)
            ->where('team_id', $this->team->id)
            ->inOwnConference($this->year)
            ->first();
    }

    /**
     * Where the team sits in its conference — "6th" — from the same cached
     * league-wide map search rows read, so this is never a per-page sort.
     *
     * Null is a real answer, and common: nobody has a position in a season
     * that has not kicked off. The line drops the phrase and keeps the
     * conference link.
     */
    #[Computed]
    public function standingPosition(): ?int
    {
        return \App\Support\TeamGlance::standingPositions($this->year)[$this->team->id] ?? null;
    }

    /**
     * Leaders, one row per category, in the order the team page presents them.
     *
     * Read straight from `team_leaders` rather than aggregated from athlete
     * stats — one indexed read instead of a fan-out.
     */
    #[Computed]
    public function leaders()
    {
        $rows = TeamLeader::with('athlete:id,display_name,headshot_url')
            ->where('team_id', $this->team->id)
            ->where('season_year', $this->statsYear)
            ->where('rank', 1)
            ->get()
            ->keyBy('category');

        return collect(TeamLeader::CATEGORIES)
            ->map(fn (string $c) => $rows->get($c))
            ->filter()
            ->values();
    }

    /**
     * The three headline leaders, each with a full stat line.
     *
     * Showing all fourteen categories as equal-weight rows meant the same
     * quarterback appeared four times over (passing, pass yards, pass TD, rush
     * TD) and nothing read as more important than anything else.
     */
    #[Computed]
    public function headlineLeaders()
    {
        return $this->leaders->whereIn('category', ['passingLeader', 'rushingLeader', 'receivingLeader'])->values();
    }

    /**
     * Individual leaders under the side of the ball they belong to.
     *
     * A flat grid of fourteen numbers made a defensive back's tackles sit
     * beside a quarterback's rating with nothing to say they are different
     * kinds of fact. Categories not named here fall under "Other" rather than
     * disappearing, because ESPN adds to this list without telling anyone.
     *
     * @return array<string, \Illuminate\Support\Collection>
     */
    #[Computed]
    public function leaderGroups(): array
    {
        $groups = [
            'Passing' => ['passingYards', 'passingTouchdowns', 'quarterbackRating'],
            'Rushing' => ['rushingYards', 'rushingTouchdowns'],
            'Receiving' => ['receivingYards', 'receivingTouchdowns', 'receptions'],
            'Defense' => ['totalTackles', 'sacks', 'interceptions'],
        ];

        $headline = ['passingLeader', 'rushingLeader', 'receivingLeader'];
        $placed = array_merge($headline, ...array_values($groups));

        $bucketed = collect($groups)
            ->map(fn (array $categories) => $this->leaders->whereIn('category', $categories)->values())
            ->put('Other', $this->leaders->whereNotIn('category', $placed)->values());

        return $bucketed->filter(fn ($rows) => $rows->isNotEmpty())->all();
    }

    #[Computed]
    public function stats()
    {
        return TeamSeasonStat::where('team_id', $this->team->id)
            ->where('season_year', $this->statsYear)
            ->get()
            ->keyBy('category');
    }

    /**
     * Team stat categories under the side of the ball they describe.
     *
     * ESPN publishes eleven flat categories; read in its order, `defensive`
     * lands first and `scoring` near the end, so the page opened on tackles
     * rather than on points. Anything ESPN adds later falls into "Other".
     *
     * @return array<string, \Illuminate\Support\Collection>
     */
    #[Computed]
    public function statGroups(): array
    {
        $groups = [
            'Offense' => ['general', 'scoring', 'passing', 'rushing', 'receiving'],
            'Defense' => ['defensive', 'defensiveInterceptions'],
            'Special Teams' => ['kicking', 'punting', 'returning'],
        ];

        $placed = array_merge(...array_values($groups));

        $bucketed = collect($groups)
            ->map(fn (array $categories) => collect($categories)
                ->map(fn (string $c) => $this->stats->get($c))
                ->filter()
                ->values())
            ->put('Other', $this->stats->reject(fn ($row, $c) => in_array($c, $placed, true))->values());

        return $bucketed->filter(fn ($rows) => $rows->isNotEmpty())->all();
    }

    public function statCategoryLabel(string $category): string
    {
        return match ($category) {
            'general' => 'Total Offense',
            'defensiveInterceptions' => 'Takeaways',
            'defensive' => 'Defense',
            default => str($category)->headline()->toString(),
        };
    }

    /**
     * This team's news.
     *
     * ESPN honours `team=` on the news endpoint — verified live, Georgia's feed
     * shares only 5 of 50 articles with the general one and reaches back weeks
     * further. Worth stating because the sibling `athlete=` parameter on the
     * same endpoint is silently IGNORED.
     *
     * The fetch is DISPATCHED, never awaited. It briefly ran inline here behind
     * a cache, which meant a page render could block on a 250 KB upstream
     * request — the exact thing that made v3's game and team pages collapse
     * under load. This reads what we have and lets the queue catch up; the job
     * is unique per team, so a busy team page is still one fetch.
     */
    #[Computed]
    public function news()
    {
        Cache::remember(
            "team:news:dispatched:{$this->team->id}",
            1800,
            function () {
                SyncTeamNews::dispatch($this->team->id);

                return true;
            }
        );

        // `teams` is eager-loaded because the article card renders team chips;
        // lazy loading is disabled app-wide, so omitting it is a hard error
        // rather than a silent N+1.
        return Article::query()
            ->with('teams:id,slug,short_display_name,abbreviation,logo,logo_dark')
            ->whereHas('teams', fn ($q) => $q->whereKey($this->team->id))
            ->newest()
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function schedule()
    {
        $seasonIds = Season::where('year', $this->year)->pluck('id');

        return Game::query()
            ->with([
                'homeTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                'awayTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                'venue:id,name',
            ])
            ->whereIn('season_id', $seasonIds)
            ->where(fn ($q) => $q->where('home_team_id', $this->team->id)->orWhere('away_team_id', $this->team->id))
            ->orderBy('kickoff_at')
            ->get();
    }

    /**
     * Roster grouped by ESPN's position groups, in depth-chart order.
     */
    #[Computed]
    public function roster()
    {
        $roster = AthleteTeamSeason::with(['athlete:id,display_name,headshot_url,display_height,display_weight,birth_city,birth_state,slug', 'position:id,abbreviation,name'])
            ->where('team_id', $this->team->id)
            ->where('season_year', $this->rosterYear)
            ->get()
            ->sortBy(fn (AthleteTeamSeason $r) => [$r->position?->abbreviation ?? 'ZZ', $r->athlete?->display_name])
            ->groupBy('position_group');

        return $this->rosterGroup === ''
            ? $roster
            : $roster->filter(fn ($players, $group) => $group === $this->rosterGroup);
    }

    /**
     * The squads this roster actually has, in ESPN's own order.
     *
     * Empty for a roster with fewer than two — 119 teams' newest roster is
     * older than the current one and therefore derived from box scores, which
     * carry a team and a jersey and NO position group. A one-tab strip is
     * chrome, not a filter.
     *
     * @return list<string>
     */
    #[Computed]
    public function rosterGroups(): array
    {
        $order = ['offense' => 0, 'defense' => 1, 'special_teams' => 2];

        $groups = AthleteTeamSeason::where('team_id', $this->team->id)
            ->where('season_year', $this->rosterYear)
            ->whereNotNull('position_group')
            ->distinct()
            ->pluck('position_group')
            ->sortBy(fn (string $g) => $order[$g] ?? 9)
            ->values()
            ->all();

        return count($groups) > 1 ? $groups : [];
    }

    /**
     * Which season's roster we can actually show.
     *
     * ESPN publishes only the CURRENT roster — asking for a past season returns
     * zero athletes. Past seasons therefore hold just the handful of players
     * resolved as statistical leaders, which is a worse answer than showing the
     * real roster and saying which year it is. So fall back to the most recent
     * roster we have, and label it.
     */
    #[Computed]
    public function rosterYear(): int
    {
        $exact = AthleteTeamSeason::where('team_id', $this->team->id)
            ->where('season_year', $this->year)
            ->count();

        // A season with only a few entries is leader backfill, not a roster.
        if ($exact >= 30) {
            return $this->year;
        }

        return (int) (AthleteTeamSeason::where('team_id', $this->team->id)->max('season_year') ?? $this->year);
    }

    /**
     * This team's signees for the class, best first.
     *
     * The class IS the page's `$year` — a recruiting class is keyed on a year
     * and the page already has a season select, so a second control would ask
     * the reader the same question twice.
     */
    #[Computed]
    public function commits()
    {
        return Recruit::query()
            ->with('position:id,abbreviation')
            ->where('committed_team_id', $this->team->id)
            ->where('recruiting_class', $this->year)
            ->orderByRaw('national_rank is null, national_rank')
            ->orderByDesc('grade')
            ->orderBy('display_name')
            ->get();
    }

    /**
     * Signees, average grade and the team's national rank IN this class.
     *
     * Read from the same ranked list the League screen renders, so the two can
     * never report a different rank for the same team.
     *
     * @return array{rank:int, signees:int, average:float|null, best:int|null}|null
     */
    #[Computed]
    public function classSummary(): ?array
    {
        return RecruitingClasses::forTeam($this->team->id, $this->year);
    }

    /**
     * Prospects this school was in on who signed somewhere else.
     *
     * The interesting half of a recruiting tab, and only possible because the
     * sync now stores the whole `schools[]` interest list rather than the
     * commitment alone — no other screen can show it.
     */
    #[Computed]
    public function missedOut()
    {
        return RecruitSchool::query()
            ->with([
                'recruit:id,display_name,recruiting_class,national_rank,grade,position_id,committed_team_id,high_school',
                'recruit.committedTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                'recruit.position:id,abbreviation',
            ])
            ->where('team_id', $this->team->id)
            ->whereHas('recruit', fn ($q) => $q
                ->where('recruiting_class', $this->year)
                ->whereNotNull('committed_team_id')
                ->where('committed_team_id', '!=', $this->team->id))
            ->get()
            ->sortBy(fn (RecruitSchool $s) => $s->recruit?->national_rank ?? PHP_INT_MAX)
            ->take(12)
            ->values();
    }

    public function groupLabel(string $group): string
    {
        return match ($group) {
            'offense' => 'Offense',
            'defense' => 'Defense',
            'special_teams' => 'Special Teams',
            'injured_reserve' => 'Injured / Out',
            'suspended' => 'Suspended',
            'practice_squad' => 'Practice Squad',
            default => str($group)->headline()->toString(),
        };
    }
}; ?>

@php $palette = $team->palette(); @endphp

<div
    class="flex flex-col gap-5"
    @style([
        '--team-accent: '.$palette?->surface => $palette,
        '--team-accent-contrast: '.$palette?->text => $palette,
        '--team-keyline: '.$team->altAccentColor() => $team->altAccentColor(),
    ])
>
    {{-- Team hero, in the team's own color. The logo rides a neutral puck
         rather than the accent — a one-color mark in its own color vanishes
         into the surface — and the alt color draws the keyline along the
         hero's bottom edge, jersey-piping style. Text color is computed from
         the accent's luminance, never assumed white.

         The identity is TWO lines, never truncated: the place, then the
         mascot underneath in a lighter italic — "App State" over
         "Mountaineers". placeName() already guarantees the first line fits. --}}
    <div class="team-accent team-keyline -mx-4 -mt-5 flex items-center gap-3 px-4 py-5">
        {{-- The puck disappears in dark mode: the surface underneath is
             already neutral, and x-team-logo swaps in the dark-mode mark. --}}
        <span class="flex size-20 shrink-0 items-center justify-center rounded-full bg-white shadow-md ring-1 ring-black/10 dark:bg-transparent dark:shadow-none dark:ring-0">
            <x-team-logo :team="$team" size="xl" />
        </span>

        <div class="flex min-w-0 flex-1 flex-col">
            <span class="text-xl font-bold leading-tight">{{ $team->placeName() }}</span>

            @if ($team->mascotName())
                <span class="text-base font-light leading-tight dark:text-zinc-400">{{ $team->mascotName() }}</span>
            @endif

            {{-- One subtle KPI pair: record, then where that record puts
                 them — "8-4 (4-4) · 6th in SEC". The position phrase is the
                 conference link, so the conference page stays one tap away.

                 `short` because the position is often absent — nobody has a
                 standing in a season that has not kicked off — and the link
                 then falls back to its own text. Left at the default that
                 would read "Southeastern Conference" here and "6th in SEC"
                 the week after, which looks like two different lines. --}}
            <span class="flex flex-wrap items-center gap-x-1.5 pt-1 text-sm dark:text-zinc-400">
                @if ($this->standing)
                    <span class="tabular">{{ $this->standing->overallRecord() }} ({{ $this->standing->conferenceRecord() }})</span>
                    <span aria-hidden="true">&middot;</span>
                @endif

                <x-conference-link :conference="$this->seasonRow?->conference" :year="$year" label="short">
                    @if ($this->standingPosition !== null && $this->seasonRow?->conference?->short_name)
                        {{ Illuminate\Support\Number::ordinal($this->standingPosition) }} in {{ $this->seasonRow->conference->short_name }}
                    @endif
                </x-conference-link>
            </span>
        </div>

        {{-- The hero's right column: the follow action, and the season the
             whole screen is scoped to underneath it.

             The season menu lives HERE rather than beside the tabs because it
             does not fit there. Measured at 390: the five tabs are 350px in a
             358px row, so a 52px control wrapped to a line of its own and cost
             the screen a whole 32px band before any content. The hero already
             had 48px of unused height beside an 80px logo. Both controls draw
             from the accent — filled for the action, outlined for the
             qualifier — so they read as one stack rather than two ideas.

             One home at every width, deliberately: a control that sits in the
             hero on a phone and beside the tabs on a laptop is two controls to
             learn. Following dispatches the per-team news fetch. --}}
        <div class="flex shrink-0 flex-col items-end gap-2.5">
            <livewire:follow-button :team="$team" :key="'follow-'.$team->id" />

            <x-season-menu
                :years="range($this->latestYear, $this->latestYear - 4)"
                :selected="$year"
                variant="accent"
            />
        </div>
    </div>

    {{--
        The team page's own sub nav — see x-team-nav for why it is not the
        gutter and not a plate. It bleeds to both edges and tucks flush under
        the hero, so hero and nav read as one block the way ESPN's team pages
        do.

        It replaced the gutter's `block` variant, which divided the row into
        five equal cells and put the widest label 5.4px over its padding
        budget at 390. Left-aligned labels with a shared gap size to their
        own words instead, so no cell can be too small for what is in it.
        Measured at 390 in a 358px row:

            labels   Schedule 59.8 + Roster 42.4 + Stats 33.0
                     + Recruits 53.0 + News 35.7   = 223.9
            gaps     4 x 20                        =  80.0
                                                     -----
                                                      303.9 in 358, 54 spare

        That headroom is the whole budget — a sixth tab or a longer word has
        to be measured, because this row deliberately does not scroll.
    --}}
    <x-team-nav
        :tabs="[
            'schedule' => 'Schedule',
            'roster' => 'Roster',
            'stats' => 'Stats',
            'recruiting' => 'Recruits',
            'news' => 'News',
        ]"
        :selected="$tab"
        model="tab"
        key-prefix="tab"
    />

    @if ($tab === 'schedule')
        <div class="flex flex-col gap-2">
            @forelse ($this->schedule as $game)
                {{-- Dated: a season's schedule is a flat four-month list, so
                     a kickoff time alone does not say which week. --}}
                <x-game-card :game="$game" date wire:key="g-{{ $game->id }}" />
            @empty
                <flux:callout icon="calendar-days">
                    <flux:callout.heading>No schedule</flux:callout.heading>
                    <flux:callout.text>Nothing on the books for {{ $year }}.</flux:callout.text>
                </flux:callout>
            @endforelse
        </div>
    @endif

    @if ($tab === 'roster')
        @if ($this->rosterYear !== $year)
            <flux:callout icon="information-circle" variant="secondary">
                <flux:callout.text>
                    ESPN publishes only the current roster, so this shows {{ $this->rosterYear }}.
                </flux:callout.text>
            </flux:callout>
        @endif

        {{--
            Squad filter as a BLOCK gutter, matching the stats scope one tab
            over: both are filters inside a section, so both fill their row
            and divide it equally rather than one sitting centered and the
            other spanning.

            The FILTER shortens two labels that the section headings below
            keep in full, because equal cells are unforgiving: measured at
            390, a four-up cell is 88px and "Special Teams" is 92.2px of
            text — over the CELL, not merely its padding, so the label would
            overhang its own active pad. "Special" beside Offense and Defense
            is the same three-phase idea in 50px and reads as the parallel it
            is. `groupLabel()` is untouched, so the heading over the players
            themselves still says Special Teams.

            Absent on a roster that has fewer than two squads — 119 teams'
            most recent roster predates the current one and is derived from
            box scores, which carry no position group at all.
        --}}
        @if ($this->rosterGroups !== [])
            @php
                $squadFilterLabels = [
                    'special_teams' => 'Special',
                    'practice_squad' => 'Practice',
                ];

                $squadItems = collect(array_merge([''], $this->rosterGroups))
                    ->map(fn ($group) => [
                        'value' => $group,
                        'label' => $group === ''
                            ? 'All'
                            : ($squadFilterLabels[$group] ?? $this->groupLabel($group)),
                    ])
                    ->all();
            @endphp

            <x-gutter-tabs
                :items="$squadItems"
                :selected="$rosterGroup"
                model="rosterGroup"
                label="Squad"
                key-prefix="squad"
                variant="block"
            />
        @endif

        @forelse ($this->roster as $group => $players)
            <div class="flex flex-col gap-2">
                <flux:subheading>{{ $this->groupLabel($group) }} <span class="text-zinc-400">({{ $players->count() }})</span></flux:subheading>

                <div class="flex flex-col divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800/60 dark:border-zinc-800">
                    @foreach ($players as $row)
                        <div class="flex items-center gap-3 p-2.5 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-900">
                            <span class="tabular w-7 shrink-0 text-right text-stat text-zinc-400">{{ $row->jersey ?? '—' }}</span>

                            <x-player-link
                                :athlete="$row->athlete"
                                :subtitle="collect([$row->position?->abbreviation, $row->experience_class, $row->athlete?->hometown()])->filter()->implode(' · ')"
                                class="flex-1"
                            />

                            <span class="shrink-0 text-micro text-zinc-400">
                                {{ collect([$row->athlete?->display_height, $row->athlete?->display_weight])->filter()->implode(', ') }}
                            </span>
                        </div>
                @endforeach
                </div>
            </div>
        @empty
            <flux:callout icon="user-group">
                <flux:callout.heading>No roster</flux:callout.heading>
                <flux:callout.text>Nothing published for this team yet.</flux:callout.text>
            </flux:callout>
        @endforelse
    @endif

    @if ($tab === 'stats')
        <div class="flex flex-col gap-4">
            {{-- Stats only exist once games have been played, so a page opened
                 on the upcoming season falls back to the last season that has
                 them — labelled, the same way the roster does. --}}
            @if ($this->statsYear !== $year)
                <flux:callout icon="information-circle" variant="secondary">
                    <flux:callout.text>
                        {{ $year }} hasn't kicked off yet, so these are {{ $this->statsYear }} numbers.
                    </flux:callout.text>
                </flux:callout>
            @endif

            {{-- Two different questions — "who on this team is good?" and "how
                 good is this team?" — so they get a toggle rather than one
                 long scroll that answers both badly.

                 PILLS, and this is the half of "two levels, two languages"
                 that moved. It was a bleeding x-plate back when the section
                 strip above was a pill gutter; now the section nav owns the
                 underline-on-a-rule idiom, and leaving this as a plate would
                 put two ruled underlined rows on one screen — a child that
                 looks exactly like its parent, which is the same confusion
                 the rule has always been about, only inverted.

                 So on a team page: NAVIGATION underlines, FILTERS INSIDE a
                 section are pills. The roster's squad filter below already
                 reads that way, so the two sub-filters now agree. The League
                 Stats screen keeps its plate — it has no hero and no nav
                 above it to collide with. Value 'players' still matches
                 /stats, so one control means one thing app-wide. --}}
            <x-gutter-tabs
                :items="['team' => 'Team', 'players' => 'Players']"
                :selected="$statsView"
                model="statsView"
                label="Stats scope"
                key-prefix="teamstats"
                variant="block"
            />

            @if ($statsView === 'players')
                @if ($this->leaders->isEmpty())
                    <flux:callout icon="chart-bar">
                        <flux:callout.heading>No leaders yet</flux:callout.heading>
                        <flux:callout.text>Nothing published for {{ $year }}.</flux:callout.text>
                    </flux:callout>
                @else
                    {{-- The three headline lines first: a full stat line each,
                         which is what a reader wants before any breakdown. --}}
                    @foreach ($this->headlineLeaders as $leader)
                        {{-- Stacks on a phone: the stat line is long enough
                             that keeping it inline truncated the player's name
                             to "Gunner…". --}}
                        <div class="flex flex-col gap-1.5 rounded-lg border border-zinc-200 p-3 sm:flex-row sm:items-center sm:gap-3 dark:border-zinc-800" wire:key="hl-{{ $leader->category }}">
                            <x-player-link
                                :athlete="$leader->athlete"
                                size="md"
                                :subtitle="\App\Models\TeamLeader::label($leader->category)"
                                class="min-w-0 sm:flex-1"
                            />

                            <span class="tabular pl-[3.25rem] text-stat text-zinc-600 sm:shrink-0 sm:pl-0 sm:text-right dark:text-zinc-300">
                                {{ $leader->display_value }}
                            </span>
                        </div>
                    @endforeach

                    @foreach ($this->leaderGroups as $group => $rows)
                        <div class="flex flex-col gap-2" wire:key="lg-{{ $group }}">
                            <flux:subheading>{{ $group }}</flux:subheading>

                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                @foreach ($rows as $leader)
                                    <a
                                        href="{{ route('player', $leader->athlete) }}"
                                        wire:navigate
                                        wire:key="l-{{ $leader->category }}"
                                        class="group flex flex-col gap-0.5 rounded-lg border border-zinc-200 p-2.5 transition-colors hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-900"
                                    >
                                        <span class="text-micro uppercase tracking-wide text-zinc-500">
                                            {{ \App\Models\TeamLeader::label($leader->category) }}
                                        </span>
                                        <span class="tabular text-base font-semibold">{{ $leader->display_value }}</span>
                                        <span class="truncate text-micro text-zinc-500 group-hover:underline">
                                            {{ $leader->athlete?->display_name }}
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @endif
            @else
                @forelse ($this->statGroups as $group => $rows)
                    <div class="flex flex-col gap-3" wire:key="sg-{{ $group }}">
                        <flux:subheading>{{ $group }}</flux:subheading>

                        @foreach ($rows as $row)
                            <div class="flex flex-col gap-1.5" wire:key="sc-{{ $row->category }}">
                                <span class="text-micro font-semibold uppercase tracking-wide text-zinc-400">
                                    {{ $this->statCategoryLabel($row->category) }}
                                </span>

                                <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800">
                                    <table class="w-full text-stat">
                                        <tbody>
                                            @foreach ($row->entries() as $stat)
                                                <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60">
                                                    <td class="px-3 py-1.5 text-zinc-500">{{ $stat['label'] }}</td>
                                                    <td class="px-3 py-1.5 text-right font-medium">{{ $stat['display'] }}</td>
                                                    {{-- ESPN ranks all 136 FBS teams on every stat it
                                                         publishes, so this comes free with the value. --}}
                                                    <td class="tabular w-14 px-3 py-1.5 text-right text-micro text-zinc-400">
                                                        @if ($stat['rank'])
                                                            {{ \App\Support\Ordinal::of($stat['rank']) }}
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <flux:callout icon="chart-bar">
                        <flux:callout.heading>No statistics</flux:callout.heading>
                        <flux:callout.text>Nothing published for {{ $year }}.</flux:callout.text>
                    </flux:callout>
                @endforelse
            @endif
        </div>
    @endif

    @if ($tab === 'recruiting')
        <div class="flex flex-col gap-4">
            @if ($this->classSummary)
                {{-- One line of context before the names: where this class
                     ranks, how big it is, how good. The rank comes from the
                     same ranked list the League screen renders. --}}
                <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
                    <span class="text-base font-semibold">
                        {{ Illuminate\Support\Number::ordinal($this->classSummary['rank']) }}
                        <span class="text-sm font-normal text-zinc-500">in the {{ $year }} class</span>
                    </span>

                    <span class="tabular text-stat text-zinc-500">
                        {{ $this->classSummary['signees'] }} signees
                        @if ($this->classSummary['average'])
                            &middot; {{ $this->classSummary['average'] }} avg grade
                        @endif
                        @if ($this->classSummary['best'])
                            &middot; best #{{ $this->classSummary['best'] }}
                        @endif
                    </span>
                </div>
            @endif

            @forelse ($this->commits as $recruit)
                {{-- `min-w-0`: a flex item's automatic minimum size is its
                     min-content width, so the row would grow to fit the longest
                     high school rather than letting the inner column clip. --}}
                <div class="-mt-2 flex min-w-0 items-center gap-3 rounded-lg border border-zinc-200 p-2.5 dark:border-zinc-800"
                     wire:key="commit-{{ $recruit->id }}">
                    <span class="tabular w-8 shrink-0 text-right text-stat font-semibold text-zinc-400">
                        {{ $recruit->national_rank ?? '—' }}
                    </span>

                    <div class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate text-sm font-medium">{{ $recruit->display_name }}</span>
                        <span class="truncate text-micro text-zinc-500">
                            {{ collect([$recruit->position?->abbreviation, $recruit->high_school])->filter()->implode(' · ') }}
                        </span>

                        {{-- Its own line — the hometown is the first thing
                             truncation eats and it takes the school with it. --}}
                        @if ($recruit->hometown())
                            <span class="truncate text-micro text-zinc-400">{{ $recruit->hometown() }}</span>
                        @endif
                    </div>

                    <span class="tabular w-8 shrink-0 text-right text-sm font-semibold">{{ $recruit->grade ?? '—' }}</span>
                </div>
            @empty
                <flux:callout icon="academic-cap">
                    <flux:callout.heading>No commitments</flux:callout.heading>
                    <flux:callout.text>
                        Nothing recorded for {{ $team->placeName() }}'s {{ $year }} class.
                    </flux:callout.text>
                </flux:callout>
            @endforelse

            @if ($this->missedOut->isNotEmpty())
                <div class="flex flex-col gap-2">
                    {{-- Only possible because the sync stores the whole
                         interest list rather than the commitment alone. --}}
                    <flux:subheading>Also recruited</flux:subheading>

                    <div class="flex flex-col divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800/60 dark:border-zinc-800">
                        @foreach ($this->missedOut as $school)
                            <div class="flex min-w-0 items-center gap-3 p-2.5" wire:key="miss-{{ $school->id }}">
                                <span class="tabular w-8 shrink-0 text-right text-stat text-zinc-400">
                                    {{ $school->recruit?->national_rank ?? '—' }}
                                </span>

                                <div class="flex min-w-0 flex-1 flex-col">
                                    <span class="truncate text-sm">{{ $school->recruit?->display_name }}</span>
                                    <span class="truncate text-micro text-zinc-500">
                                        {{ collect([$school->recruit?->position?->abbreviation, $school->recruit?->high_school])->filter()->implode(' · ') }}
                                    </span>
                                </div>

                                <span class="shrink-0 text-micro text-zinc-400">signed with</span>

                                <x-team-link :team="$school->recruit?->committedTeam" label="abbr" size="xs"
                                             class="shrink-0 text-zinc-500" />
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if ($tab === 'news')
        @forelse ($this->news as $article)
            <x-article-card :article="$article" wire:key="tnews-{{ $article->id }}" />
        @empty
            <flux:callout icon="newspaper">
                <flux:callout.heading>No news</flux:callout.heading>
                <flux:callout.text>
                    ESPN's feed only reaches back a few days, so this fills in as news is synced.
                </flux:callout.text>
            </flux:callout>
        @endforelse
    @endif

    {{-- Below the facts here too, and NOT a sixth tab: the team nav is a
         measured 358px row with 54px spare that deliberately does not
         scroll, and a tab plus its gap spends nearly all of it. The talk
         belongs to the team rather than to Schedule or News, so it sits at
         the foot of every tab instead of inside one. --}}
    <div class="border-t border-zinc-200 pt-6 dark:border-zinc-800">
        <livewire:conversation :topic="$team" :key="'talk-team-'.$team->id" />
    </div>
</div>
