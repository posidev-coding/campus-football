<?php

use App\Jobs\FetchAthleteGameLog;
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
 * The game log is the one genuinely per-athlete feed. Opening the page
 * DISPATCHES a refresh when the log is due one and renders whatever we already
 * hold; nobody waits on an upstream round trip to read a page. Freshness is a
 * timestamp on the athlete, and the window is a day wider off gameday than on —
 * see SyncAthleteStats.
 */
new class extends Component
{
    /**
     * How long to keep waiting on a dispatched job before giving the reader
     * their controls back.
     *
     * A failed fetch deliberately leaves `game_log_fetched_at` alone, so
     * "landed" can never arrive for it — without a ceiling the page would poll
     * forever under a spinner and the refresh button would never appear.
     */
    private const WAIT_CEILING = 30;

    public Athlete $athlete;

    /**
     * When we asked for a refresh, and the stamp the athlete carried when we
     * did. The job changing that stamp is how the page knows it came back.
     *
     * Unix seconds, not Carbon: these ride through Livewire's snapshot, and a
     * date object round-trips as `__PHP_Incomplete_Class`.
     */
    public ?int $queuedAt = null;

    public ?int $stampAtQueue = null;

    public function mount(Athlete $athlete, SyncAthleteStats $stats): void
    {
        $this->athlete = $athlete;

        // Dispatch, never fetch. The job is unique on the athlete, so a player
        // trending after a big game costs one request rather than one per
        // viewer.
        if ($stats->isStale($athlete)) {
            $this->queue();
        }
    }

    /**
     * A refresh the reader asked for, on a log that is not due one.
     *
     * Forced past the staleness check — the whole point is that they want it
     * now — but still behind the service's in-flight lock, which is what keeps
     * a public button from becoming a way to hammer ESPN.
     */
    public function refreshGameLog(): void
    {
        $this->queue(force: true);
    }

    private function queue(bool $force = false): void
    {
        $this->stampAtQueue = $this->athlete->game_log_fetched_at?->getTimestamp();
        $this->queuedAt = now()->getTimestamp();

        FetchAthleteGameLog::dispatch($this->athlete->id, $force);
    }

    /**
     * Whether a dispatched refresh is still outstanding.
     *
     * `$athlete` is re-resolved from the database on every request — Livewire
     * stores a model as its key, not its attributes — so a poll sees the stamp
     * the job wrote without asking for it explicitly.
     */
    #[Computed]
    public function refreshing(): bool
    {
        if ($this->queuedAt === null) {
            return false;
        }

        $stamp = $this->athlete->game_log_fetched_at?->getTimestamp();

        // The job stamped a new time: it is back, whatever it found.
        if ($stamp !== $this->stampAtQueue) {
            return false;
        }

        // Or it stamped the SAME second the previous fetch carried, which the
        // column cannot tell apart — `timestamp` has no sub-second precision,
        // so a refresh landing inside a second of the last one would otherwise
        // look like it never arrived.
        if ($stamp !== null && $stamp >= $this->queuedAt) {
            return false;
        }

        return now()->getTimestamp() - $this->queuedAt < self::WAIT_CEILING;
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

    {{-- The poll belongs HERE rather than on the empty state: a player who
         already has rows shows the table while a refresh is outstanding, so a
         poll scoped to the empty branch would never bring the button back for
         them. It reads only our own database and stops as soon as the job
         lands or the wait ceiling passes. --}}
    <div class="flex flex-col gap-2" @if ($this->refreshing) wire:poll.2s @endif>
        <div class="flex items-center justify-between gap-3">
            <flux:subheading>Game log</flux:subheading>

            {{-- Only once nothing is outstanding. Offering "Refresh" while the
                 job dispatched on page load is still in flight invites a second
                 request for the answer already on its way, and reads as though
                 the first one failed. --}}
            @if (! $this->refreshing)
                <flux:button wire:click="refreshGameLog" size="xs" variant="ghost">Refresh</flux:button>
            @else
                <span class="text-micro text-zinc-400">Refreshing…</span>
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
        @elseif ($this->refreshing)
            <flux:callout icon="chart-bar" variant="secondary">
                <flux:callout.text>Fetching {{ $athlete->display_name }}'s game log…</flux:callout.text>
            </flux:callout>
        @else
            <flux:callout icon="chart-bar">
                <flux:callout.heading>No game log</flux:callout.heading>
                <flux:callout.text>{{ $athlete->display_name }} has no recorded stats yet.</flux:callout.text>
            </flux:callout>
        @endif
    </div>
</div>
