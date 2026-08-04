<?php

use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeasonStat;
use App\Services\CfbCalendar;
use App\Support\Scope;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * National team statistics.
 *
 * Every stat ESPN publishes for a team carries its NATIONAL RANK — it has
 * already ranked all 136 FBS teams on "average gain" and everything else. So
 * this screen is a sort over stored data rather than a computation, and it costs
 * no extra requests at all: the ranks arrive with the team stats sync.
 *
 * Ordering is by ESPN's rank where present, falling back to the raw value. The
 * fallback matters because rank is only meaningful within the classification
 * ESPN ranked — an FCS team's "3rd" is not comparable with an FBS team's.
 */
new class extends Component
{
    #[Url]
    public ?int $year = null;

    #[Url]
    public string $scope = Scope::FBS;

    #[Url]
    public string $category = 'scoring';

    #[Url]
    public string $stat = '';

    public function mount(CfbCalendar $calendar): void
    {
        $this->year ??= $this->latestYear() ?? $calendar->resultsYear();
        $this->stat = $this->stat ?: ($this->stats()[0]['value'] ?? '');
    }

    public function updatedCategory(): void
    {
        // The chosen stat belongs to the old category and will not exist in the
        // new one, so re-resolve rather than render an empty table.
        $this->stat = $this->stats()[0]['value'] ?? '';
    }

    private function latestYear(): ?int
    {
        return Cache::remember('stats:latest-year', 3600, fn () => TeamSeasonStat::max('season_year'));
    }

    /** @return list<int> */
    #[Computed]
    public function years(): array
    {
        return Cache::remember('stats:years', 3600, fn () => TeamSeasonStat::query()
            ->distinct()->orderByDesc('season_year')->pluck('season_year')->all());
    }

    /** @return list<string> */
    #[Computed]
    public function categories(): array
    {
        return Cache::remember("stats:categories:{$this->year}", 3600, fn () => TeamSeasonStat::query()
            ->where('season_year', $this->year)
            ->distinct()->orderBy('category')->pluck('category')->all());
    }

    /**
     * The stats available inside the chosen category, read off a sample row.
     *
     * @return list<array{value:string, label:string}>
     */
    #[Computed]
    public function stats(): array
    {
        $sample = TeamSeasonStat::where('season_year', $this->year)
            ->where('category', $this->category)
            ->where('season_type', Season::REGULAR)
            ->first();

        if ($sample === null) {
            return [];
        }

        return collect($sample->entries())
            ->map(fn (array $s) => ['value' => $s['name'], 'label' => $s['label']])
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * @return list<array{team:?Team, display:?string, value:?float, rank:?int}>
     */
    #[Computed]
    public function rows(): array
    {
        if ($this->stat === '') {
            return [];
        }

        $teamIds = Scope::teamIds($this->scope, $this->year);

        $query = TeamSeasonStat::query()
            ->with('team:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark')
            ->where('season_year', $this->year)
            ->where('season_type', Season::REGULAR)
            ->where('category', $this->category);

        if ($teamIds !== null) {
            $query->whereIn('team_id', $teamIds);
        }

        return $query->get()
            ->map(fn (TeamSeasonStat $row) => [
                'team' => $row->team,
                'display' => $row->stat($this->stat)['display'],
                'value' => $row->stat($this->stat)['value'],
                'rank' => $row->stat($this->stat)['rank'],
            ])
            ->filter(fn (array $r) => $r['display'] !== null)
            // Rank ascending where ESPN gave us one; value descending otherwise.
            ->sortBy(fn (array $r) => $r['rank'] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }
}; ?>

<div class="flex flex-col gap-4">
    <x-scope-filter title="Team Stats" :year="$year" :selected="$scope" />

    <div class="flex flex-wrap gap-2">
        <flux:select wire:model.live="category" size="sm" class="w-36">
            @foreach ($this->categories as $c)
                <flux:select.option :value="$c">{{ str($c)->headline() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="stat" size="sm" class="min-w-40 flex-1">
            @foreach ($this->stats as $s)
                <flux:select.option :value="$s['value']">{{ $s['label'] }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="year" size="sm" class="w-24">
            @foreach ($this->years as $y)
                <flux:select.option :value="$y">{{ $y }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->rows !== [])
        <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800">
            <table class="w-full min-w-md text-stat">
                <thead>
                    <tr class="border-b border-zinc-200 text-micro uppercase tracking-wide text-zinc-500 dark:border-zinc-800">
                        <th class="w-10 px-3 py-2 text-right font-medium">#</th>
                        <th class="px-2 py-2 text-left font-medium">Team</th>
                        <th class="px-3 py-2 text-right font-medium">Value</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->rows as $index => $row)
                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60"
                            wire:key="stat-{{ $row['team']?->id }}">
                            <td class="tabular px-3 py-2 text-right text-zinc-400">{{ $index + 1 }}</td>
                            <td class="px-2 py-2">
                                <x-team-link :team="$row['team']" size="sm" />
                            </td>
                            <td class="tabular px-3 py-2 text-right font-semibold">{{ $row['display'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <flux:callout icon="chart-bar">
            <flux:callout.heading>No statistics</flux:callout.heading>
            <flux:callout.text>Nothing published for this season yet.</flux:callout.text>
        </flux:callout>
    @endif
</div>
