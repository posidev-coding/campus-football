<?php

use App\Models\Ranking;
use App\Models\Season;
use App\Models\Week;
use App\Services\CfbCalendar;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Poll rankings, with the poll, season and week all switchable.
 *
 * Defaults come from CfbCalendar rather than from config or "latest season",
 * because a season exists in the database months before any poll is published
 * for it — selecting on year alone lands the user on an empty page.
 */
new class extends Component
{
    #[Url]
    public string $poll = 'ap';

    #[Url]
    public ?int $year = null;

    #[Url]
    public ?int $week = null;

    public function mount(CfbCalendar $calendar): void
    {
        $this->year ??= $calendar->rankingsYear($this->poll);
        $this->week ??= $calendar->latestRankingsWeek($this->year, $this->poll);
    }

    /**
     * Switching poll re-resolves the week, because polls do not all run for the
     * same weeks — the CFP rankings only start in November.
     */
    public function updatedPoll(CfbCalendar $calendar): void
    {
        $this->year = $calendar->rankingsYear($this->poll);
        $this->week = $calendar->latestRankingsWeek($this->year, $this->poll);
    }

    public function updatedYear(CfbCalendar $calendar): void
    {
        $this->week = $calendar->latestRankingsWeek($this->year, $this->poll);
    }

    /** Polls that actually have rows, labelled. @return array<string,string> */
    #[Computed]
    public function polls(): array
    {
        $available = Cache::remember(
            'rankings:polls',
            3600,
            fn () => Ranking::query()->distinct()->pluck('poll')->all()
        );

        $labels = [
            'ap' => 'AP Top 25',
            'usa' => 'Coaches',
            'cfp' => 'CFP',
            'fcs' => 'FCS Coaches',
            'afca' => 'AFCA Div II',
        ];

        return collect($available)
            ->mapWithKeys(fn (string $p) => [$p => $labels[$p] ?? str($p)->upper()->toString()])
            ->all();
    }

    /** @return list<int> */
    #[Computed]
    public function years(): array
    {
        return Cache::remember(
            "rankings:years:{$this->poll}",
            3600,
            fn () => Season::query()
                ->whereIn('id', Ranking::where('poll', $this->poll)->distinct()->pluck('season_id'))
                ->orderByDesc('year')
                ->pluck('year')
                ->unique()
                ->values()
                ->all()
        );
    }

    /** @return list<array{number:int, name:string}> */
    #[Computed]
    public function weeks(): array
    {
        $season = Season::where('year', $this->year)->where('type', Season::REGULAR)->first();

        if ($season === null) {
            return [];
        }

        return Cache::remember(
            "rankings:weeks:{$season->id}:{$this->poll}",
            3600,
            fn () => Week::query()
                ->whereIn('id', Ranking::where('season_id', $season->id)->where('poll', $this->poll)->distinct()->pluck('week_id'))
                ->orderByDesc('number')
                ->get(['number', 'name'])
                ->map(fn (Week $w) => ['number' => $w->number, 'name' => $w->name ?? "Week {$w->number}"])
                ->all()
        );
    }

    #[Computed]
    public function rankings()
    {
        $season = Season::where('year', $this->year)->where('type', Season::REGULAR)->first();

        if ($season === null) {
            return collect();
        }

        return Ranking::query()
            ->with('team:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark')
            ->where('season_id', $season->id)
            ->where('poll', $this->poll)
            ->when($this->week, fn ($q) => $q->whereHas('week', fn ($w) => $w->where('number', $this->week)))
            ->orderBy('rank')
            ->get();
    }
}; ?>

<div class="flex flex-col gap-4">
    <flux:heading size="xl">Rankings</flux:heading>

    <div class="flex flex-wrap gap-2">
        <flux:select wire:model.live="poll" size="sm" class="min-w-36">
            @foreach ($this->polls as $value => $label)
                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="year" size="sm" class="w-28">
            @foreach ($this->years as $y)
                <flux:select.option :value="$y">{{ $y }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="week" size="sm" class="min-w-32 flex-1">
            @foreach ($this->weeks as $w)
                <flux:select.option :value="$w['number']">{{ $w['name'] }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @forelse ($this->rankings as $entry)
        @php $movement = $entry->previous_rank ? $entry->previous_rank - $entry->rank : 0; @endphp

        <div class="flex items-center gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800"
             wire:key="rank-{{ $entry->id }}">
            <span class="tabular w-6 shrink-0 text-right text-base font-bold">{{ $entry->rank }}</span>

            <x-team-link :team="$entry->team" size="sm" class="min-w-0 flex-1" />

            <span class="tabular hidden shrink-0 text-stat text-zinc-500 sm:block">{{ $entry->record }}</span>

            <span class="tabular hidden w-16 shrink-0 text-right text-stat text-zinc-500 sm:block">
                {{ number_format($entry->points) }}
            </span>

            @if ($entry->first_place_votes > 0)
                <flux:badge size="sm" color="amber" class="hidden shrink-0 sm:inline-flex">
                    {{ $entry->first_place_votes }} first
                </flux:badge>
            @endif

            <span class="w-9 shrink-0 text-right text-micro font-medium">
                @if ($movement > 0)
                    <span class="text-emerald-600 dark:text-emerald-400">▲{{ $movement }}</span>
                @elseif ($movement < 0)
                    <span class="text-red-600 dark:text-red-400">▼{{ abs($movement) }}</span>
                @elseif ($entry->previous_rank === null)
                    <span class="text-zinc-400">NR</span>
                @else
                    <span class="text-zinc-300 dark:text-zinc-600">—</span>
                @endif
            </span>
        </div>
    @empty
        <flux:callout icon="trophy">
            <flux:callout.heading>No poll published</flux:callout.heading>
            <flux:callout.text>Nothing for this poll, season and week.</flux:callout.text>
        </flux:callout>
    @endforelse
</div>
