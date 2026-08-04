<?php

use App\Models\Team;
use App\Models\TeamSeasonStat;
use App\Support\Ordinal;
use App\Support\Scope;
use App\Support\Stats\LeaderQuery;
use App\Support\Stats\StatCatalog;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * National team statistics, grouped Offense / Defense / Special Teams the way
 * ESPN's own stats page does.
 *
 * Ranked WITHIN the selected scope. ESPN publishes a national rank on every
 * team stat and it is carried alongside for context, but it is the wrong number
 * to order by the moment a reader picks a conference — the SEC's best offence
 * should be row 1, not row 7.
 *
 * No Top 25 scope: it filters teams, which makes "the best offence among 25
 * teams" read as if it were the national best.
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
        $this->year ??= Cache::remember(
            'stats:latest-year',
            3600,
            fn () => TeamSeasonStat::max('season_year')
                ?? app(App\Services\CfbCalendar::class)->resultsYear()
        );

        $this->normaliseScope();
    }

    /**
     * See the note on the leaders screen: Top 25 filters teams, so honouring it
     * here would present "best offence among 25 teams" as the national best.
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

    /** @return list<int> */
    #[Computed]
    public function years(): array
    {
        return Cache::remember('stats:years', 3600, fn () => TeamSeasonStat::query()
            ->distinct()->orderByDesc('season_year')->pluck('season_year')->all());
    }

    /** @return array<string, string> */
    #[Computed]
    public function sides(): array
    {
        return StatCatalog::sideLabels();
    }

    /**
     * @return list<array{group:string, boards:list<array<string, mixed>>}>
     */
    #[Computed]
    public function groups(): array
    {
        $groups = [];

        foreach (StatCatalog::groups($this->side, team: true) as $group) {
            $boards = [];

            foreach (StatCatalog::boardsFor($this->side, $group, team: true) as $board) {
                $rows = LeaderQuery::teams($board, $this->year, $this->scope, limit: 5);

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

    #[Computed]
    public function teams()
    {
        $ids = collect($this->groups)->pluck('boards')->flatten(1)->pluck('rows')->flatten(1)->pluck('team_id');

        return Team::whereIn('id', $ids)
            ->get(['id', 'slug', 'display_name', 'short_display_name', 'abbreviation', 'logo', 'logo_dark'])
            ->keyBy('id');
    }
}; ?>

<div class="flex flex-col gap-4">
    <h1 class="sr-only">Team Stats</h1>

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
        <div class="flex flex-col gap-2" wire:key="tgrp-{{ $side }}-{{ $group['group'] }}">
            <flux:subheading>{{ $group['group'] }}</flux:subheading>

            <div class="grid gap-3 lg:grid-cols-2">
                @foreach ($group['boards'] as $board)
                    <div class="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-800"
                         wire:key="tbrd-{{ $board['meta']['category'] }}-{{ $board['meta']['stat'] }}">
                        <header class="border-b border-zinc-100 px-3 py-2 dark:border-zinc-800/60">
                            <h3 class="text-stat font-semibold">{{ $board['meta']['label'] }}</h3>
                        </header>

                        <ol class="flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800/60">
                            @foreach ($board['rows'] as $row)
                                <li class="flex items-center gap-2 px-3 py-1.5">
                                    <span class="tabular w-4 shrink-0 text-right text-micro font-semibold text-zinc-400">
                                        {{ $row['rank'] }}
                                    </span>

                                    <x-team-link :team="$this->teams->get($row['team_id'])" label="short"
                                                 size="xs" class="min-w-0 flex-1" />

                                    {{-- ESPN's national rank, kept as context when the
                                         scope is narrower than the whole division. --}}
                                    @if ($row['national'] && $scope !== Scope::FBS)
                                        <span class="tabular shrink-0 text-micro text-zinc-400">
                                            {{ Ordinal::of($row['national']) }}
                                        </span>
                                    @endif

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
            <flux:callout.text>Nothing published for {{ $year }} yet.</flux:callout.text>
        </flux:callout>
    @endforelse
</div>
