<?php

use App\Enums\Poll;
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
    public string $poll = '';

    #[Url]
    public ?int $year = null;

    /** A week id, not a number — releases span three season types. */
    #[Url]
    public ?int $release = null;

    public function mount(CfbCalendar $calendar): void
    {
        // CFP once it exists for the season, AP until then.
        $this->poll = $this->poll ?: $calendar->defaultPoll()->value;
        $this->year ??= $calendar->rankingsYear($this->poll);
        $this->release ??= $calendar->latestRankingRelease($this->year, $this->poll);
    }

    /**
     * Switching poll re-resolves the week, because polls do not all run for the
     * same weeks — the CFP rankings only start in November.
     */
    public function updatedPoll(CfbCalendar $calendar): void
    {
        $this->year = $calendar->rankingsYear($this->poll);
        $this->release = $calendar->latestRankingRelease($this->year, $this->poll);
    }

    public function updatedYear(CfbCalendar $calendar): void
    {
        $this->release = $calendar->latestRankingRelease($this->year, $this->poll);
    }

    /**
     * Polls with rows for this season, majors first.
     *
     * @return array<string,string>
     */
    #[Computed]
    public function polls(): array
    {
        return collect(app(CfbCalendar::class)->availablePolls($this->year))
            ->mapWithKeys(fn (Poll $p) => [$p->value => $p->label()])
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

    /**
     * Poll releases for this season, newest first — spanning the preseason
     * poll, the weekly polls, and the final rankings.
     *
     * @return list<array{week_id:int, label:string}>
     */
    #[Computed]
    public function releases(): array
    {
        return app(CfbCalendar::class)->rankingReleases($this->year, $this->poll);
    }

    #[Computed]
    public function rankings()
    {
        // Spans season types: the preseason poll and the final rankings live
        // outside the regular season.
        $seasonIds = Season::where('year', $this->year)->pluck('id');

        if ($seasonIds->isEmpty()) {
            return collect();
        }

        return Ranking::query()
            ->with('team:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark')
            ->whereIn('season_id', $seasonIds)
            ->where('poll', $this->poll)
            ->when($this->release, fn ($q) => $q->where('week_id', $this->release))
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

        <flux:select wire:model.live="release" size="sm" class="min-w-36 flex-1">
            @foreach ($this->releases as $r)
                <flux:select.option :value="$r['week_id']">{{ $r['label'] }}</flux:select.option>
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
