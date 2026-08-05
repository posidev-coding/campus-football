<?php

use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Models\Standing;
use App\Services\CfbCalendar;
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

    public function mount(CfbCalendar $calendar): void
    {
        $this->year ??= $calendar->resultsYear();
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
            // `location` because the table renders placeName(). Omit it from a
            // constrained eager load and every team silently falls back to its
            // display name, which reads as a design decision rather than a
            // missing column.
            ->with(['team:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark', 'conference:id,name,short_name,abbreviation,logo'])
            ->where('season_year', $this->year)
            ->whereIn('conference_id', $conferenceIds)
            ->inStandingsOrder()
            ->get()
            ->groupBy(fn (Standing $s) => $s->conference?->name ?? 'Independent');
    }
}; ?>

<div class="flex flex-col gap-4">
    <h1 class="sr-only">Standings</h1>

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
            <flux:subheading>
                <x-conference-link :conference="$rows->first()?->conference" :year="$year" />
            </flux:subheading>

            {{--
                Six columns onto a 390px phone with no wrapping and no
                horizontal scroll. Four things working together, each measured:

                - NO `min-w-*`. It forced a scroll on a table that can fit.
                - `w-full max-w-0` on the team cell (below) so it absorbs the
                  slack; a `<td>` sizes to min-content and ignores `min-w-0`.
                - ABBREVIATED headers. They, not the values, set the widths —
                  "Overall" claimed 69px for a value needing 30, and the team
                  name paid for it. The full words survive as `sr-only`.
                - `whitespace-nowrap` HERE rather than per cell. A record column
                  sized to its abbreviated header is narrower than a
                  four-character record, so "13-0" wrapped and made the top
                  three rows of every conference 6px taller than the rest. The
                  team cell overrides it with `truncate`, which is the one place
                  text may be cut instead of wrapped.

                Result at 390px: the team column went 108px -> 158px and the
                names that no longer fit went from 90 of 136 to 4, each clipping
                by a pixel or three.
            --}}
            <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800">
                <table class="w-full text-stat whitespace-nowrap">
                    <thead>
                        <tr class="border-b border-zinc-200 text-micro uppercase tracking-wide text-zinc-500 dark:border-zinc-800">
                            {{-- Headers are abbreviated because they, not the
                                 values, were setting the column widths: at
                                 390px "Overall" claimed 69px for a value
                                 needing 30, and the team name paid for it. The
                                 full words survive as `sr-only`, so nothing is
                                 lost to a screen reader. --}}
                            <th scope="col" class="px-2 py-2 text-left font-medium">Team</th>
                            <th scope="col" class="px-1.5 py-2 text-right font-medium">
                                <span aria-hidden="true">Conf</span>
                                <span class="sr-only">Conference record</span>
                            </th>
                            <th scope="col" class="px-1.5 py-2 text-right font-medium">
                                <span aria-hidden="true">Ovr</span>
                                <span class="sr-only">Overall record</span>
                            </th>
                            <th scope="col" class="px-1.5 py-2 text-right font-medium">
                                <span aria-hidden="true">PF</span>
                                <span class="sr-only">Points for</span>
                            </th>
                            <th scope="col" class="px-1.5 py-2 text-right font-medium">
                                <span aria-hidden="true">PA</span>
                                <span class="sr-only">Points against</span>
                            </th>
                            <th scope="col" class="px-2 py-2 text-right font-medium">
                                <span aria-hidden="true">Strk</span>
                                <span class="sr-only">Streak</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60">
                                {{-- `w-full max-w-0` is what lets this fit: a
                                     `<td>` sizes to its content's min-content
                                     width and ignores `min-w-0`, so a name set
                                     `nowrap` by `truncate` made the whole table
                                     grow. Zeroing the max width lets the cell
                                     be told its size, and `w-full` hands it
                                     whatever the record columns leave. --}}
                                <td class="w-full max-w-0 px-2 py-2">
                                    {{-- Place, not mascot. --}}
                                    <x-team-link :team="$row->team" label="location" />
                                </td>
                                <td class="px-1.5 py-2 text-right font-semibold">{{ $row->conferenceRecord() }}</td>
                                <td class="px-1.5 py-2 text-right text-zinc-500">{{ $row->overallRecord() }}</td>
                                <td class="px-1.5 py-2 text-right text-zinc-500">{{ $row->points_for }}</td>
                                <td class="px-1.5 py-2 text-right text-zinc-500">{{ $row->points_against }}</td>
                                <td class="px-2 py-2 text-right">
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
