<?php

use App\Models\Athlete;
use App\Models\AthleteSeasonStat;
use App\Models\Team;
use App\Support\Scope;
use App\Support\Stats\LeaderQuery;
use App\Support\Stats\StatCatalog;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * National statistical leaders, derived from our own box scores.
 *
 * NOT from ESPN's national leaders feed, which was the original source and the
 * wrong one for a scoped screen: it spans every division, only about half its
 * top 100 is FBS, and narrowing to a conference collapsed it — the MAC had FOUR
 * players in the national top 100 for passing yards. Ranking our own season
 * aggregates gives that conference 43 and numbers them 1..N.
 *
 * Grouped Offense / Defense / Special Teams, following ESPN's own stats page,
 * because that is how people look for this rather than alphabetically.
 *
 * There is deliberately no Top 25 scope here. It filters TEAMS, which is
 * meaningful on a scoreboard and misleading on a leaderboard — "leading rusher
 * among 25 teams" reads as if it were the national leader.
 */
new class extends Component
{
    #[Url]
    public ?int $year = null;

    #[Url]
    public string $scope = Scope::FBS;

    #[Url]
    public string $side = StatCatalog::OFFENSE;

    public function mount(): void
    {
        $this->year ??= $this->latestYear();

        $this->normaliseScope();
    }

    /**
     * Top 25 is not offered here, so a bookmarked URL carrying it — or a user
     * arriving from Scores via wire:navigate with it still in the querystring —
     * would otherwise silently scope a leaderboard to 25 teams and present it
     * as if it were national.
     */
    public function updatedScope(): void
    {
        $this->normaliseScope();
    }

    private function normaliseScope(): void
    {
        if ($this->scope === Scope::TOP_25) {
            $this->scope = Scope::FBS;
        }
    }

    private function latestYear(): int
    {
        return Cache::remember(
            'leaders:derived-year',
            3600,
            fn () => AthleteSeasonStat::max('season_year')
                ?? app(App\Services\CfbCalendar::class)->resultsYear()
        );
    }

    /** @return list<int> */
    #[Computed]
    public function years(): array
    {
        return Cache::remember('leaders:years', 3600, fn () => AthleteSeasonStat::query()
            ->distinct()->orderByDesc('season_year')->pluck('season_year')->all());
    }

    /** @return array<string, string> */
    #[Computed]
    public function sides(): array
    {
        return StatCatalog::sideLabels();
    }

    /**
     * Every leaderboard on the current side, grouped, with its rows resolved.
     *
     * @return list<array{group:string, boards:list<array<string, mixed>>}>
     */
    #[Computed]
    public function groups(): array
    {
        $groups = [];

        foreach (StatCatalog::groups($this->side) as $group) {
            $boards = [];

            foreach (StatCatalog::boardsFor($this->side, $group) as $board) {
                $rows = LeaderQuery::players($board, $this->year, $this->scope, limit: 5);

                if ($rows !== []) {
                    $boards[] = ['meta' => $board, 'rows' => $rows];
                }
            }

            if ($boards !== []) {
                $groups[] = ['group' => $group, 'boards' => $boards];
            }
        }

        return $groups;
    }

    /**
     * Athletes and teams for everything on screen, in two queries.
     *
     * Resolved in bulk rather than per row: a side renders up to ten
     * leaderboards of five, and looking each one up individually would be 50
     * round trips for what two `whereIn`s answer.
     */
    #[Computed]
    public function athletes()
    {
        $ids = collect($this->groups)->pluck('boards')->flatten(1)->pluck('rows')->flatten(1)->pluck('athlete_id');

        return Athlete::whereIn('id', $ids)->get(['id', 'slug', 'display_name', 'short_name', 'headshot_url'])->keyBy('id');
    }

    #[Computed]
    public function teams()
    {
        $ids = collect($this->groups)->pluck('boards')->flatten(1)->pluck('rows')->flatten(1)->pluck('team_id')->filter();

        return Team::whereIn('id', $ids)
            ->get(['id', 'slug', 'display_name', 'short_display_name', 'abbreviation', 'logo', 'logo_dark'])
            ->keyBy('id');
    }
}; ?>

<div class="flex flex-col gap-4">
    <h1 class="sr-only">Player Stats</h1>

    {{-- One row, not three. The section strip already names the screen, so
         everything here is a qualifier on it. --}}
    <div class="flex flex-wrap items-center gap-2">
        <x-scope-filter :year="$year" :selected="$scope" :top25="false" class="shrink-0" />

        <div class="-mx-1 min-w-0 flex-1 overflow-x-auto px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <flux:radio.group wire:model.live="side" variant="segmented" size="sm" class="w-max">
                @foreach ($this->sides as $value => $label)
                    <flux:radio :value="$value" :label="$label" />
                @endforeach
            </flux:radio.group>
        </div>

        <flux:select wire:model.live="year" size="sm" class="w-24 shrink-0">
            @foreach ($this->years as $y)
                <flux:select.option :value="$y">{{ $y }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @forelse ($this->groups as $group)
        <div class="flex flex-col gap-2" wire:key="grp-{{ $side }}-{{ $group['group'] }}">
            <flux:subheading>{{ $group['group'] }}</flux:subheading>

            <div class="grid gap-3 lg:grid-cols-2">
                @foreach ($group['boards'] as $board)
                    <div class="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-800"
                         wire:key="brd-{{ $board['meta']['stat'] }}">
                        <header class="flex items-baseline justify-between gap-2 border-b border-zinc-100 px-3 py-2 dark:border-zinc-800/60">
                            <h3 class="text-stat font-semibold">{{ $board['meta']['label'] }}</h3>

                            @if (isset($board['meta']['min']))
                                {{-- Stated, not hidden: a rate leaderboard with no
                                     floor is won by whoever attempted once. --}}
                                <span class="text-micro text-zinc-400">
                                    min {{ $board['meta']['min'][1] }}
                                </span>
                            @endif
                        </header>

                        <ol class="flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800/60">
                            @foreach ($board['rows'] as $row)
                                @php
                                    $athlete = $this->athletes->get($row['athlete_id']);
                                    $team = $this->teams->get($row['team_id']);
                                @endphp

                                <li class="flex items-center gap-2 px-3 py-1.5">
                                    <span class="tabular w-4 shrink-0 text-right text-micro font-semibold text-zinc-400">
                                        {{ $row['rank'] }}
                                    </span>

                                    <div class="flex min-w-0 flex-1 items-center gap-1.5">
                                        @if ($athlete)
                                            <x-player-link :athlete="$athlete" size="xs" />
                                        @else
                                            <span class="truncate text-micro text-zinc-500">Unknown player</span>
                                        @endif

                                        <x-team-link :team="$team" label="abbr" size="xs" :logo="false"
                                                     class="shrink-0 text-zinc-400" />
                                    </div>

                                    <span class="tabular shrink-0 text-stat font-semibold">{{ $row['display'] }}</span>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <flux:callout icon="chart-bar">
            <flux:callout.heading>No statistics</flux:callout.heading>
            <flux:callout.text>
                Nothing derived for {{ $year }} yet. Run <code>php artisan cfb:aggregate</code>.
            </flux:callout.text>
        </flux:callout>
    @endforelse
</div>
