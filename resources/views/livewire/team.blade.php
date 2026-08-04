<?php

use App\Jobs\SyncTeamNews;
use App\Models\Article;
use App\Models\AthleteTeamSeason;
use App\Models\Game;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamLeader;
use App\Models\TeamSeasonStat;
use App\Services\CfbCalendar;
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

    /** Within Stats: 'leaders' (individual) or 'team'. */
    #[Url]
    public string $statsView = 'leaders';

    public function mount(Team $team, CfbCalendar $calendar): void
    {
        $this->team = $team;
        $this->year ??= $calendar->resultsYear();
    }

    #[Computed]
    public function latestYear(): int
    {
        return app(CfbCalendar::class)->resultsYear();
    }

    #[Computed]
    public function seasonRow(): ?\App\Models\TeamSeason
    {
        return $this->team->seasonFor($this->year)->with('conference')->first();
    }

    #[Computed]
    public function standing(): ?Standing
    {
        return Standing::fromEspn()
            ->where('season_year', $this->year)
            ->where('team_id', $this->team->id)
            ->first();
    }

    /**
     * Where the team sits in its conference — "6th" — from the same cached
     * league-wide map search rows read, so this is never a per-page sort.
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
            ->where('season_year', $this->year)
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
            ->where('season_year', $this->year)
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
        return AthleteTeamSeason::with(['athlete:id,display_name,headshot_url,display_height,display_weight,birth_city,birth_state,slug', 'position:id,abbreviation,name'])
            ->where('team_id', $this->team->id)
            ->where('season_year', $this->rosterYear)
            ->get()
            ->sortBy(fn (AthleteTeamSeason $r) => [$r->position?->abbreviation ?? 'ZZ', $r->athlete?->display_name])
            ->groupBy('position_group');
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
        '--team-accent-far: '.$palette?->far => $palette,
        '--team-accent-contrast: '.$palette?->text => $palette,
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
    <div
        class="team-gradient -mx-4 -mt-5 flex items-center gap-3 px-4 py-5"
        @style(['border-bottom: 3px solid '.$team->altAccentColor() => $team->altAccentColor()])
    >
        <span class="flex size-20 shrink-0 items-center justify-center rounded-full bg-white shadow-md ring-1 ring-black/10 dark:bg-zinc-950 dark:ring-white/15">
            <x-team-logo :team="$team" size="xl" />
        </span>

        <div class="flex min-w-0 flex-1 flex-col">
            <span class="text-xl font-bold leading-tight">{{ $team->placeName() }}</span>

            @if ($team->mascotName())
                <span class="text-base font-light leading-tight opacity-90">{{ $team->mascotName() }}</span>
            @endif

            {{-- One subtle KPI pair: record, then where that record puts
                 them — "8-4 (4-4) · 6th in SEC". The position phrase is the
                 conference link, so the conference page stays one tap away. --}}
            <span class="flex flex-wrap items-center gap-x-1.5 pt-1 text-sm opacity-90">
                @if ($this->standing)
                    <span class="tabular">{{ $this->standing->overallRecord() }} ({{ $this->standing->conferenceRecord() }})</span>
                    <span aria-hidden="true">&middot;</span>
                @endif

                <x-conference-link :conference="$this->seasonRow?->conference" :year="$year">
                    @if ($this->standingPosition !== null && $this->seasonRow?->conference?->short_name)
                        {{ Illuminate\Support\Number::ordinal($this->standingPosition) }} in {{ $this->seasonRow->conference->short_name }}
                    @endif
                </x-conference-link>
            </span>
        </div>

        {{-- Back in the hero, but drawing its own colors from it rather than
             from a fixed Flux variant — see the component. Following
             dispatches the per-team news fetch, which fills the News tab. --}}
        <livewire:follow-button :team="$team" :key="'follow-'.$team->id" class="shrink-0 self-start" />
    </div>

    {{-- Tabs left, season right, on one row. The tab strip scrolls inside its
         own track — four tabs plus the select will not fit at 390px, and a
         segmented control that overflows silently clips the last one — while
         the select stays put as a fixed-width sibling. `min-w-0` is what lets
         the track actually shrink instead of pushing the select off-screen. --}}
    <div class="flex items-center justify-between gap-3">
        <div class="-ml-4 min-w-0 overflow-x-auto pl-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <flux:radio.group wire:model.live="tab" variant="segmented" size="sm" class="w-max">
                <flux:radio value="schedule" label="Schedule" />
                <flux:radio value="roster" label="Roster" />
                <flux:radio value="stats" label="Stats" />
                <flux:radio value="news" label="News" />
            </flux:radio.group>
        </div>

        {{-- No label: four-digit years in a narrow select are self-evident,
             and the label was the widest thing on the row. --}}
        <flux:select wire:model.live="year" size="sm" class="w-24 shrink-0" aria-label="Season">
            @foreach (range($this->latestYear, $this->latestYear - 4) as $y)
                <flux:select.option :value="$y">{{ $y }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($tab === 'schedule')
        <div class="flex flex-col gap-2">
            @forelse ($this->schedule as $game)
                <x-game-card :game="$game" wire:key="g-{{ $game->id }}" />
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
            {{-- Two different questions — "who on this team is good?" and "how
                 good is this team?" — so they get a toggle rather than one
                 long scroll that answers both badly.

                 Underlined tabs, NOT another segmented pill group: this is a
                 scope filter INSIDE the tab the strip above already selected,
                 and rendering both the same way made a child look like a
                 sibling. Full width at 390px, natural width from `sm`. The
                 rule runs edge to edge on a phone by cancelling the layout
                 container's padding, the same trick the scoreboard chrome
                 uses. --}}
            <div class="-mx-4 flex border-b border-zinc-200 px-4 sm:mx-0 sm:px-0 dark:border-zinc-800">
                {{-- "Players", not "Leaders": the scope is who is on the team,
                     and the leaders are simply how that scope is presented. --}}
                @foreach (['leaders' => 'Players', 'team' => 'Team'] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('statsView', '{{ $value }}')"
                        wire:key="statsview-{{ $value }}"
                        @if ($statsView === $value) aria-current="page" @endif
                        @class([
                            'flex-1 border-b-2 px-2 pb-2.5 text-sm font-medium transition-colors sm:flex-none sm:px-4',
                            'border-zinc-900 text-zinc-900 dark:border-zinc-100 dark:text-zinc-100' => $statsView === $value,
                            'border-transparent text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100' => $statsView !== $value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>

            @if ($statsView === 'leaders')
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
</div>
