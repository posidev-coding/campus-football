<?php

use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Models\Standing;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Conference standings.
 *
 * In v3 this route was commented out and the component rendered the literal
 * word "Standings" twice — the feature never shipped in three versions because
 * the underlying data could not be made correct.
 *
 * Everything here reads the `espn` source, which is authoritative. The
 * `computed` source exists purely as a cross-check and is surfaced in the admin
 * panel, not here.
 */
new class extends Component
{
    #[Url]
    public ?int $year = null;

    #[Url]
    public string $classification = 'FBS';

    /** String for the same reason as on the scoreboard — see that component. */
    #[Url]
    public string $conference = '';

    public function mount(): void
    {
        $this->year ??= config('cfb.season');
    }

    #[Computed]
    public function seasons(): array
    {
        return Cache::remember('standings:seasons', 3600, fn () => Standing::query()
            ->distinct()
            ->orderByDesc('season_year')
            ->pluck('season_year')
            ->all());
    }

    /**
     * Conferences with published standings this season, in this division.
     * Read through conference_seasons because classification is season-scoped.
     */
    /** @return list<array{id:int, name:string}> */
    #[Computed]
    public function conferences(): array
    {
        return Cache::remember(
            "standings:conferences:{$this->year}:{$this->classification}",
            3600,
            fn () => Conference::query()
                ->whereIn('id', ConferenceSeason::where('season_year', $this->year)
                    ->where('classification', $this->classification)
                    ->pluck('conference_id'))
                ->where('is_conference', true)
                ->whereIn('id', Standing::fromEspn()->where('season_year', $this->year)->distinct()->pluck('conference_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Conference $c) => ['id' => $c->id, 'name' => $c->name])
                ->all()
        );
    }

    /**
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Standing>>
     */
    #[Computed]
    public function standings()
    {
        $conferenceIds = $this->conference !== ''
            ? [(int) $this->conference]
            : array_column($this->conferences, 'id');

        if ($conferenceIds === []) {
            return collect();
        }

        return Standing::query()
            ->fromEspn()
            ->with(['team:id,display_name,logo,logo_dark,slug', 'conference:id,name'])
            ->where('season_year', $this->year)
            ->whereIn('conference_id', $conferenceIds)
            ->inStandingsOrder()
            ->get()
            ->groupBy(fn (Standing $s) => $s->conference?->name ?? 'Independent');
    }
}; ?>

<div class="flex flex-col gap-4">
    <flux:heading size="xl">Standings</flux:heading>

    <div class="flex flex-wrap gap-2">
        <flux:select wire:model.live="year" size="sm" class="w-28">
            @foreach ($this->seasons as $season)
                <flux:select.option :value="$season">{{ $season }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="classification" size="sm" class="w-28">
            <flux:select.option value="FBS">FBS</flux:select.option>
            <flux:select.option value="FCS">FCS</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="conference" size="sm" class="min-w-40 flex-1">
            <flux:select.option value="">All conferences</flux:select.option>
            @foreach ($this->conferences as $c)
                <flux:select.option :value="$c['id']">{{ $c['name'] }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @forelse ($this->standings as $conferenceName => $rows)
        <div class="flex flex-col gap-2">
            <flux:subheading>{{ $conferenceName }}</flux:subheading>

            {{-- Scrolls within its own container so the page body never
                 scrolls sideways on a phone. --}}
            <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800">
                <table class="w-full min-w-md text-stat">
                    <thead>
                        <tr class="border-b border-zinc-200 text-micro uppercase tracking-wide text-zinc-500 dark:border-zinc-800">
                            <th class="px-3 py-2 text-left font-medium">Team</th>
                            <th class="px-2 py-2 text-right font-medium">Conf</th>
                            <th class="px-2 py-2 text-right font-medium">Overall</th>
                            <th class="px-2 py-2 text-right font-medium">PF</th>
                            <th class="px-2 py-2 text-right font-medium">PA</th>
                            <th class="px-3 py-2 text-right font-medium">Strk</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60">
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        @if ($row->team?->logo)
                                            <img src="{{ $row->team->logo }}" alt="" loading="lazy" class="size-5 shrink-0 object-contain">
                                        @endif
                                        <span class="truncate">{{ $row->team?->display_name ?? 'Unknown' }}</span>
                                    </div>
                                </td>
                                <td class="px-2 py-2 text-right font-semibold">{{ $row->conferenceRecord() }}</td>
                                <td class="px-2 py-2 text-right text-zinc-500">{{ $row->overallRecord() }}</td>
                                <td class="px-2 py-2 text-right text-zinc-500">{{ $row->points_for }}</td>
                                <td class="px-2 py-2 text-right text-zinc-500">{{ $row->points_against }}</td>
                                <td class="px-3 py-2 text-right">
                                    @if ($row->streak)
                                        <span class="{{ str_starts_with($row->streak, 'W') ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                            {{ $row->streak }}
                                        </span>
                                    @else
                                        <span class="text-zinc-400">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <flux:callout icon="table-cells">
            <flux:callout.heading>No standings yet</flux:callout.heading>
            <flux:callout.text>Nothing published for this season and division.</flux:callout.text>
        </flux:callout>
    @endforelse
</div>
