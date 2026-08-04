<?php

use App\Models\NationalLeader;
use App\Models\Season;
use App\Services\CfbCalendar;
use App\Support\Scope;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * National statistical leaders.
 *
 * Backed by the cheapest feed in the app: one core-api request returns 13
 * categories of 100 athletes. The site equivalent 404s, so core is the only
 * source — the same shape of trap as the rankings endpoint refusing to serve
 * the CFP poll.
 *
 * The feed spans every division, so scoping through team_seasons is not
 * optional. Without it an FCS player sits alongside an FBS one with nothing to
 * distinguish them.
 */
new class extends Component
{
    #[Url]
    public ?int $year = null;

    #[Url]
    public string $scope = Scope::FBS;

    #[Url]
    public string $category = '';

    /** How many rows before "show more". */
    public int $limit = 25;

    public function mount(CfbCalendar $calendar): void
    {
        $this->year ??= $this->latestYearWithLeaders() ?? $calendar->resultsYear();
        $this->category = $this->category ?: ($this->categories()[0]['value'] ?? '');
    }

    public function updatedYear(): void
    {
        $this->limit = 25;
    }

    public function updatedCategory(): void
    {
        $this->limit = 25;
    }

    public function showAll(): void
    {
        $this->limit = 100;
    }

    private function latestYearWithLeaders(): ?int
    {
        return Cache::remember(
            'leaders:latest-year',
            3600,
            fn () => NationalLeader::max('season_year')
        );
    }

    /** @return list<int> */
    #[Computed]
    public function years(): array
    {
        return Cache::remember('leaders:years', 3600, fn () => NationalLeader::query()
            ->distinct()
            ->orderByDesc('season_year')
            ->pluck('season_year')
            ->all());
    }

    /**
     * Categories that have rows for this season, in a reading order that puts
     * offense before defense rather than leaving them alphabetical.
     *
     * @return list<array{value:string, label:string}>
     */
    #[Computed]
    public function categories(): array
    {
        $preferred = [
            'passingYards', 'passingTouchdowns', 'quarterbackRating',
            'rushingYards', 'rushingTouchdowns',
            'receivingYards', 'receptions', 'receivingTouchdowns',
            'totalTackles', 'sacks', 'interceptions', 'interceptionYards',
        ];

        $present = NationalLeader::where('season_year', $this->year)
            ->distinct()
            ->pluck('category')
            ->all();

        return collect($preferred)
            ->filter(fn (string $c) => in_array($c, $present, true))
            ->merge(collect($present)->reject(fn (string $c) => in_array($c, $preferred, true)))
            ->map(fn (string $c) => ['value' => $c, 'label' => str($c)->headline()->toString()])
            ->values()
            ->all();
    }

    #[Computed]
    public function leaders()
    {
        if ($this->category === '') {
            return collect();
        }

        $query = NationalLeader::query()
            ->with([
                'athlete:id,slug,display_name,short_name,headshot_url',
                'team:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark',
            ])
            ->where('season_year', $this->year)
            ->where('season_type', Season::REGULAR)
            ->where('category', $this->category);

        $teamIds = Scope::teamIds($this->scope, $this->year);

        if ($teamIds !== null) {
            $query->whereIn('team_id', $teamIds);
        }

        return $query->orderBy('rank')->limit($this->limit)->get();
    }
}; ?>

<div class="flex flex-col gap-4">
    <x-scope-filter title="Leaders" :year="$year" :selected="$scope" />

    <div class="flex flex-wrap gap-2">
        <flux:select wire:model.live="category" size="sm" class="min-w-40 flex-1">
            @foreach ($this->categories as $c)
                <flux:select.option :value="$c['value']">{{ $c['label'] }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="year" size="sm" class="w-24">
            @foreach ($this->years as $y)
                <flux:select.option :value="$y">{{ $y }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @forelse ($this->leaders as $leader)
        <div class="flex items-center gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800"
             wire:key="leader-{{ $leader->id }}">
            <span class="tabular w-6 shrink-0 text-right text-stat font-semibold text-zinc-400">
                {{ $leader->rank }}
            </span>

            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                {{-- The athlete may be missing: ESPN publishes only the CURRENT
                     roster, so a leader from an earlier season has no roster row
                     to have come from. Degrade to the team rather than blank. --}}
                @if ($leader->athlete)
                    <x-player-link :athlete="$leader->athlete" size="sm" />
                @else
                    <span class="text-sm text-zinc-500">Unidentified player</span>
                @endif

                <x-team-link :team="$leader->team" label="short" size="xs" :logo="false" class="text-zinc-500" />
            </div>

            <span class="tabular shrink-0 text-base font-bold tracking-tight">
                {{ $leader->display_value ?? $leader->value }}
            </span>
        </div>
    @empty
        <flux:callout icon="chart-bar">
            <flux:callout.heading>No leaders</flux:callout.heading>
            <flux:callout.text>Nothing published for this category and season.</flux:callout.text>
        </flux:callout>
    @endforelse

    @if ($this->leaders->isNotEmpty() && $limit < 100)
        <flux:button wire:click="showAll" size="sm" variant="ghost" class="self-center">
            Show top 100
        </flux:button>
    @endif
</div>
