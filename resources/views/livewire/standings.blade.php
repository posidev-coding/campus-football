<?php

use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Models\Standing;
use App\Services\CfbCalendar;
use App\Support\Remember;
use App\Support\Scope;
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

    /**
     * One WHO filter — 'fbs', 'fcs', or a conference id as a string — through
     * the same scope vocabulary as every other League screen. This replaced a
     * classification select and a conference select that together were a
     * second dialect for the same question.
     */
    #[Url]
    public string $scope = Scope::FBS;

    public function mount(CfbCalendar $calendar): void
    {
        $this->year ??= $this->defaultYear($calendar);

        $this->normaliseScope();
    }

    /**
     * The season being played, the moment ESPN publishes standings for it —
     * which is months before kickoff, as 0-0 rows. That is what ESPN's own
     * site shows in August, and it fills in for real the moment week 1
     * completes. resultsYear() stays as the fallback so a fresh database
     * without the upcoming season still opens on a season that has rows.
     *
     * Remember::filled, not Cache::remember: the existence check must not pin
     * "no rows yet" for a TTL while the standings sync is landing them.
     */
    private function defaultYear(CfbCalendar $calendar): int
    {
        $year = $calendar->scoreboardYear();

        $published = Remember::filled(
            "standings:published:{$year}",
            3600,
            fn (): ?bool => Standing::fromEspn()->where('season_year', $year)->exists() ?: null
        );

        return $published ? $year : $calendar->resultsYear();
    }

    /**
     * Needed in BOTH places: `#[Url]` hydrates from the querystring without
     * firing the update hook, so a stale bookmark (`?classification=FCS`
     * predates this property) must fall back to the default on first load.
     */
    public function updatedScope(): void
    {
        $this->normaliseScope();
    }

    private function normaliseScope(): void
    {
        if (! in_array($this->scope, [Scope::FBS, Scope::FCS], true) && ! ctype_digit($this->scope)) {
            $this->scope = Scope::FBS;
        }
    }

    #[Computed]
    public function seasons(): array
    {
        // Remember::filled, not Cache::remember: standings fill through a
        // seed running long after its command exits, and a request racing it
        // must not pin an empty season menu for a TTL.
        return Remember::filled('standings:seasons', 3600, fn () => Standing::query()
            ->distinct()
            ->orderByDesc('season_year')
            ->pluck('season_year')
            ->all());
    }

    /**
     * Which division the scope sits in — the active sub-tab.
     *
     * FBS and FCS are sub-tabs rather than menu options here because they are
     * different LISTS, not a narrowing of one: the overwhelming majority of
     * readers never leave FBS, and burying the split in a dropdown made the
     * common case share a menu with the case almost nobody wants. A conference
     * id belongs to a division too, so the tab stays lit while the menu
     * narrows within it.
     */
    #[Computed]
    public function division(): string
    {
        if (! ctype_digit($this->scope)) {
            return $this->scope;
        }

        $classification = Cache::remember(
            "standings:division:{$this->year}:{$this->scope}",
            3600,
            fn () => ConferenceSeason::where('season_year', $this->year)
                ->where('conference_id', (int) $this->scope)
                ->value('classification') ?? 'FBS'
        );

        return strtolower($classification) === Scope::FCS ? Scope::FCS : Scope::FBS;
    }

    /**
     * The active division's conferences with published standings.
     * Read through conference_seasons because classification is season-scoped.
     *
     * @return list<array{id:int, label:string}>
     */
    #[Computed]
    public function conferences(): array
    {
        // `v2`: the cached shape changed from a list of ids to id+label rows,
        // and a stale entry from before the change fatalled the live page
        // while every test passed on its fresh array cache. A shape change
        // gets a new key, never a coordinated cache:clear.
        return Cache::remember(
            "standings:conferences:v2:{$this->year}:{$this->division}",
            3600,
            fn () => Conference::query()
                ->whereIn('id', ConferenceSeason::where('season_year', $this->year)
                    ->where('classification', strtoupper($this->division))
                    ->pluck('conference_id'))
                ->where('is_conference', true)
                ->whereIn('id', Standing::fromEspn()->where('season_year', $this->year)->distinct()->pluck('conference_id'))
                ->orderBy('name')
                ->get(['id', 'short_name', 'name'])
                ->map(fn (Conference $c) => ['id' => $c->id, 'label' => $c->short_name ?: $c->name])
                ->all()
        );
    }

    /**
     * The conference menu: "All FBS" (or FCS), then the division's
     * conferences. Values are scope values, so picking one just moves $scope
     * within the division the tabs selected.
     *
     * @return list<array{value:string, label:string}>
     */
    #[Computed]
    public function conferenceItems(): array
    {
        return [
            ['value' => $this->division, 'label' => 'All '.strtoupper($this->division)],
            ...array_map(fn (array $c) => ['value' => (string) $c['id'], 'label' => $c['label']], $this->conferences),
        ];
    }

    /**
     * The conference ids the scope resolves to — a division means every
     * conference in it with published standings, a digit means that one.
     *
     * @return list<int>
     */
    #[Computed]
    public function conferenceIds(): array
    {
        if (ctype_digit($this->scope)) {
            return [(int) $this->scope];
        }

        return array_column($this->conferences, 'id');
    }

    /**
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Standing>>
     */
    #[Computed]
    public function standings()
    {
        $conferenceIds = $this->conferenceIds;

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

    {{--
        FBS | FCS as underlined sub-tabs — two different LISTS, in the same
        layout as Stats — with the conference menu and season select on the
        ruled row. The tabs write $scope directly ('fbs'/'fcs'), which also
        resets any conference narrowing from the other division.
    --}}
    <x-plate
        :tabs="['fbs' => 'FBS', 'fcs' => 'FCS']"
        :selected="$this->division"
        model="scope"
        key-prefix="division"
    >
        <x-slot:actions>
            <x-filter-menu
                :items="$this->conferenceItems"
                :selected="$scope"
                model="scope"
                label="Filter by conference"
                key-prefix="conf"
                align="end"
                class="shrink-0"
            />

            <x-season-menu :years="$this->seasons" :selected="$year" class="shrink-0" />
        </x-slot:actions>
    </x-plate>

    {{-- Two conference tables abreast from `lg` — the "dense sports site"
         read the desktop aspiration in docs/ui-system.md names, and three from
         the widest. The tables keep their abbreviated headers on purpose:
         full-word headers would spend the team column's gain again.

         The cell arithmetic is the whole reason this screen carries no rail.
         Six columns were measured to fit exactly at 390px, so a cell narrower
         than that breaks them. Beside a 288px rail this grid gave 328px cells
         at `lg` — narrower than the phone it was designed for. Full width
         gives 484px at `lg` and 453px in three columns at `2xl`.

         `items-start` because these are panels, not cards: stretching a
         four-row conference to match a sixteen-row one paints a tall empty
         box under the short table. --}}
    <div class="flex flex-col gap-4 lg:grid lg:grid-cols-2 lg:items-start lg:gap-x-6 lg:gap-y-5 2xl:grid-cols-3">
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
        {{-- Spans whatever the grid is, or an empty state reads as a layout
             bug sitting in the first column. --}}
        <flux:callout icon="table-cells" class="lg:col-span-2 2xl:col-span-3">
            <flux:callout.heading>No standings yet</flux:callout.heading>
            <flux:callout.text>Nothing published for this season and division.</flux:callout.text>
        </flux:callout>
    @endforelse
    </div>
</div>
