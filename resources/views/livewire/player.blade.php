<?php

use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\Team;
use App\Services\Espn\Sync\SyncAthleteStats;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * A player page, built from structured facts rather than narrative.
 *
 * ESPN has no prose bio for college athletes — `/athletes/{id}/bio` returns only
 * team history — so this leans on measurables, hometown, class, and production.
 *
 * The game log is the one genuinely per-athlete feed, so it is fetched lazily on
 * first view and cached. Concurrent viewers collapse into a single upstream
 * request; see SyncAthleteStats.
 */
new class extends Component
{
    public Athlete $athlete;

    public bool $logLoaded = false;

    public function mount(Athlete $athlete): void
    {
        $this->athlete = $athlete;
    }

    /**
     * Deferred so the page paints immediately and the log fills in after —
     * the fetch is an upstream round-trip and must not block first render.
     */
    public function loadGameLog(SyncAthleteStats $stats): void
    {
        $stats->refreshGameLog($this->athlete->id);

        $this->logLoaded = true;

        unset($this->gameLog);
    }

    #[Computed]
    public function season()
    {
        return $this->athlete->latestSeason()->with(['team', 'position'])->first();
    }

    #[Computed]
    public function gameLog()
    {
        return AthleteGameStat::with(['game.homeTeam:id,abbreviation,logo', 'game.awayTeam:id,abbreviation,logo'])
            ->where('athlete_id', $this->athlete->id)
            ->get()
            ->sortByDesc(fn (AthleteGameStat $s) => $s->game?->kickoff_at)
            ->values();
    }

    /**
     * Column order and headings for the log.
     *
     * Read from `display_stats`, not from `array_keys($stats)`: MySQL's JSON
     * type reorders object keys on write, so the keyed stats map cannot be
     * trusted for ordering. The ordered names are stored as a JSON array,
     * which does preserve order.
     *
     * Two shapes are tolerated because two syncs write this column. The game
     * summary stores `{name, label}` pairs carrying ESPN's own headings —
     * C/ATT, YDS, AVG, TD, INT, QBR — which beat anything we could name
     * ourselves. Older rows hold a flat list of names, which falls back to the
     * hand-written table below.
     *
     * @return list<array{name:string, label:string}>
     */
    #[Computed]
    public function logColumns(): array
    {
        $first = $this->gameLog->first();

        if ($first === null) {
            return [];
        }

        $columns = $first->display_stats ?: array_keys($first->stats ?? []);

        return collect($columns)
            ->map(fn (array|string $column) => is_array($column)
                ? ['name' => $column['name'], 'label' => $column['label'] ?? $this->statLabel($column['name'])]
                : ['name' => $column, 'label' => $this->statLabel($column)])
            ->values()
            ->all();
    }

    public function statLabel(string $name): string
    {
        return match ($name) {
            'completions' => 'CMP', 'passingAttempts' => 'ATT', 'passingYards' => 'YDS',
            'completionPct' => 'CMP%', 'passingTouchdowns' => 'TD', 'interceptions' => 'INT',
            'longPassing' => 'LNG', 'sacks' => 'SACK', 'QBRating' => 'RTG', 'adjQBR' => 'QBR',
            'rushingAttempts' => 'CAR', 'rushingYards' => 'RUSH', 'yardsPerRushAttempt' => 'AVG',
            'rushingTouchdowns' => 'RTD', 'longRushing' => 'LNG',
            'receptions' => 'REC', 'receivingYards' => 'REC YDS', 'receivingTouchdowns' => 'REC TD',
            'totalTackles' => 'TCK', 'soloTackles' => 'SOLO',
            default => str($name)->headline()->upper()->toString(),
        };
    }
}; ?>

<div class="flex flex-col gap-5">
    <div class="flex items-start gap-4">
        @if ($athlete->headshot_url)
            <img src="{{ $athlete->headshot_url }}" alt=""
                 class="size-20 shrink-0 rounded-full bg-zinc-100 object-cover dark:bg-zinc-800">
        @else
            <div class="flex size-20 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-lg font-semibold text-zinc-400 dark:bg-zinc-800">
                {{ str($athlete->display_name)->substr(0, 1) }}
            </div>
        @endif

        <div class="flex min-w-0 flex-col gap-1">
            <flux:heading size="xl" class="truncate">{{ $athlete->display_name }}</flux:heading>

            @if ($this->season?->team)
                <x-team-link :team="$this->season->team" class="text-zinc-600 dark:text-zinc-400" />
            @endif

            <div class="flex flex-wrap gap-1.5 pt-0.5">
                @foreach (collect([
                    $this->season?->jersey ? '#'.$this->season->jersey : null,
                    $this->season?->position?->name,
                    $this->season?->experience_class,
                ])->filter() as $chip)
                    <flux:badge size="sm">{{ $chip }}</flux:badge>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Measurables and origin. All structured — ESPN publishes no scouting
         prose for college players. --}}
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        @foreach ([
            'Height' => $athlete->display_height,
            'Weight' => $athlete->display_weight,
            'Hometown' => $athlete->hometown(),
            'Class' => $this->season?->experience_class,
        ] as $label => $value)
            <div class="flex flex-col gap-0.5 rounded-lg border border-zinc-200 p-2.5 dark:border-zinc-800">
                <span class="text-micro uppercase tracking-wide text-zinc-500">{{ $label }}</span>
                <span class="truncate text-sm font-medium">{{ $value ?: '—' }}</span>
            </div>
        @endforeach
    </div>

    <div class="flex flex-col gap-2">
        <div class="flex items-center justify-between">
            <flux:subheading>Game log</flux:subheading>

            @if (! $logLoaded && $this->gameLog->isEmpty())
                <flux:button wire:click="loadGameLog" size="xs" variant="ghost" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="loadGameLog">Load</span>
                    <span wire:loading wire:target="loadGameLog">Loading…</span>
                </flux:button>
            @endif
        </div>

        @if ($this->gameLog->isNotEmpty())
            <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800">
                <table class="w-full text-stat">
                    <thead>
                        <tr class="border-b border-zinc-200 text-micro uppercase tracking-wide text-zinc-500 dark:border-zinc-800">
                            <th class="px-3 py-2 text-left font-medium">Game</th>
                            @foreach ($this->logColumns as $column)
                                <th class="px-2 py-2 text-right font-medium">{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->gameLog as $row)
                            <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60">
                                <td class="whitespace-nowrap px-3 py-1.5">
                                    <span class="tabular text-zinc-400">{{ $row->game?->kickoff_at?->format('M j') }}</span>
                                    <span class="ml-1.5">{{ $row->game?->short_name }}</span>
                                </td>
                                @foreach ($this->logColumns as $column)
                                    <td class="tabular px-2 py-1.5 text-right">{{ $row->stats[$column['name']] ?? '—' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif ($logLoaded)
            <flux:callout icon="chart-bar">
                <flux:callout.heading>No game log</flux:callout.heading>
                <flux:callout.text>{{ $athlete->display_name }} has no recorded stats yet.</flux:callout.text>
            </flux:callout>
        @else
            <flux:callout icon="chart-bar" variant="secondary">
                <flux:callout.text>Game logs load on demand.</flux:callout.text>
            </flux:callout>
        @endif
    </div>
</div>
