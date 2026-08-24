<?php

use App\Models\Position;
use App\Models\Recruit;
use App\Support\RecruitingClasses;
use App\Support\Scope;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Recruiting classes — the whole class, not the top of it.
 *
 * This screen used to render a top-200 leaderboard because only 25 prospects
 * existed. A class is ~5,200 and eight are held, so it is built on the /players
 * pattern instead: search, position pills, a scope filter, sort, and a list
 * that grows as you scroll.
 *
 * Prospects who never enrol still matter here — 6-12% of a class stays
 * Undecided — which is why the scope filter has to be careful not to hide them.
 */
new class extends Component
{
    /** Rows per load — the initial page and each subsequent chunk. */
    private const CHUNK = 50;

    /** @var array<string, string> */
    public const SORTS = [
        'rank' => 'Rank',
        'grade' => 'Grade',
        'name' => 'Name',
        'team' => 'Team',
    ];

    #[Url]
    public ?int $year = null;

    #[Url]
    public string $q = '';

    #[Url]
    public string $scope = Scope::FBS;

    /** An ABBREVIATION, never a position id — ESPN's ids duplicate. */
    #[Url]
    public string $position = '';

    #[Url]
    public string $sort = 'rank';

    /** 'players' | 'teams' */
    #[Url]
    public string $view = 'players';

    public int $perPage = self::CHUNK;

    public function mount(?int $year = null): void
    {
        $this->year = $year ?? $this->latestClass();

        $this->normaliseSort();
    }

    /**
     * Any filter change collapses back to the first chunk — otherwise a reader
     * who narrows the list keeps scrolling through rows they just excluded.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'scope', 'position', 'sort', 'year', 'view'], true)) {
            $this->perPage = self::CHUNK;
        }

        if ($property === 'year') {
            // A position valid in one class may not exist in another.
            $this->position = '';
        }
    }

    /**
     * Needed in BOTH places: `#[Url]` hydrates from the querystring without
     * firing the update hook, so a bookmarked `?sort=nonsense` would reach the
     * query builder as a column name.
     */
    public function updatedSort(): void
    {
        $this->normaliseSort();
    }

    private function normaliseSort(): void
    {
        if (! array_key_exists($this->sort, self::SORTS)) {
            $this->sort = 'rank';
        }
    }

    public function selectPosition(string $abbreviation): void
    {
        $this->position = $this->position === $abbreviation ? '' : $abbreviation;

        $this->perPage = self::CHUNK;
    }

    public function loadMore(): void
    {
        if ($this->perPage < $this->total) {
            $this->perPage += self::CHUNK;
        }
    }

    private function latestClass(): int
    {
        return (int) (Recruit::max('recruiting_class') ?? config('cfb.season'));
    }

    /** @return list<int> */
    #[Computed]
    public function classes(): array
    {
        return Cache::remember(
            'recruiting:classes',
            3600,
            fn () => Recruit::query()->distinct()->orderByDesc('recruiting_class')->pluck('recruiting_class')->all()
        );
    }

    /**
     * Positions in this class, as ABBREVIATIONS, in depth-chart order.
     *
     * Keyed on the abbreviation because ESPN's position ids duplicate — `LS`
     * resolves to two of them — so a control built from ids renders the same
     * label twice and each entry hides the other's prospects.
     *
     * Ordered by `positions.parent_id` (70 offense, 71 defense, 72 special)
     * rather than the `position_group` string the roster tables carry, because
     * recruits have no group column. Same intent: offense, defense, special
     * teams, then squad size — alphabetical buries QB seventeenth.
     *
     * @return list<string>
     */
    #[Computed]
    public function positions(): array
    {
        return Cache::remember("recruiting:positions:{$this->year}", 3600, function () {
            $rank = [70 => 0, 71 => 1, 72 => 2];

            return DB::table('recruits as r')
                ->join('positions as p', 'p.id', '=', 'r.position_id')
                ->where('r.recruiting_class', $this->year)
                ->groupBy('p.abbreviation', 'p.parent_id')
                ->selectRaw('p.abbreviation as abbreviation, p.parent_id as parent_id, count(*) as prospects')
                ->get()
                // One abbreviation can arrive under two ids; fold them.
                ->groupBy('abbreviation')
                ->map(fn ($rows) => [
                    'abbreviation' => $rows->first()->abbreviation,
                    'parent_id' => $rows->sortByDesc('prospects')->first()->parent_id,
                    'prospects' => $rows->sum('prospects'),
                ])
                ->sortBy(fn (array $p) => [$rank[$p['parent_id']] ?? 9, -$p['prospects']])
                ->pluck('abbreviation')
                ->values()
                ->all();
        });
    }

    /**
     * The position filter's menu items — same shape as /players.
     *
     * Note the vocabulary is recruiting's own: the real quarterbacks are
     * QB-PP and QB-DT, so most rows have no descriptive twin and read as the
     * bare abbreviation.
     *
     * @return list<array{value:string, label:string, menuLabel?:string}>
     */
    #[Computed]
    public function positionItems(): array
    {
        if ($this->positions === []) {
            return [];
        }

        $names = Position::whereIn('abbreviation', $this->positions)
            ->descriptive()
            ->get()
            ->unique('abbreviation')
            ->keyBy('abbreviation');

        $items = [['value' => '', 'label' => 'All positions']];

        foreach ($this->positions as $abbreviation) {
            $name = $names->get($abbreviation)?->pluralName();

            $items[] = [
                'value' => $abbreviation,
                'label' => $abbreviation,
                'menuLabel' => $name && $name !== $abbreviation
                    ? "{$abbreviation} · {$name}"
                    : $abbreviation,
            ];
        }

        return $items;
    }

    /** "Search Quarterbacks…" once a position is picked. */
    #[Computed]
    public function searchPlaceholder(): string
    {
        if ($this->position === '') {
            return 'Search prospects…';
        }

        $name = Position::where('abbreviation', $this->position)
            ->descriptive()
            ->first()
            ?->pluralName();

        return 'Search '.($name ?: $this->position).'…';
    }

    /**
     * The season to resolve a conference scope against.
     *
     * NOT the recruiting class. `team_seasons` stops at the newest season we
     * hold, and `Scope::teamIds('fbs', 2028)` returns an EMPTY array rather
     * than "everyone" — which excluded every committed prospect in the 2027 and
     * 2028 classes.
     *
     * Asked of the DATA rather than the calendar: `currentYear()` falls back to
     * config when no seasons are loaded, which is a year that may itself have
     * no memberships. Conference membership is a fact about the school, so the
     * newest membership year at or before the class is the right question.
     */
    #[Computed]
    public function scopeYear(): int
    {
        return (int) (App\Models\TeamSeason::where('season_year', '<=', $this->year)->max('season_year')
            ?? $this->year);
    }

    /**
     * Every prospect the filters admit — shared by the rows and the total, so
     * the two cannot disagree about what "load more" has left to load.
     */
    private function filtered(): Builder
    {
        $teamIds = Scope::teamIds($this->scope, $this->scopeYear());

        $positionIds = $this->position === ''
            ? []
            : Position::where('abbreviation', $this->position)->pluck('id')->all();

        return Recruit::query()
            ->where('recruiting_class', $this->year)
            /*
             * Scope filters on TEAMS, so a conference would silently swallow
             * every uncommitted prospect — 640 of the 2026 class. They belong
             * to no team and cannot be judged on one, so they stay in, the same
             * escape hatch the scoreboard gives an unannounced fixture.
             */
            ->when($teamIds !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereIn('committed_team_id', $teamIds)->orWhereNull('committed_team_id')
            ))
            ->when($positionIds !== [], fn ($q) => $q->whereIn('position_id', $positionIds))
            ->when($this->q !== '', function ($q) {
                $term = Search::term($this->q);

                // Prefix on either half of the name, matching Search::recruits()
                // and the model's own #[SearchUsingPrefix].
                $q->where(fn ($w) => $w
                    ->where('display_name', 'like', $term.'%')
                    ->orWhere('last_name', 'like', $term.'%'));
            });
    }

    #[Computed]
    public function total(): int
    {
        return $this->filtered()->count();
    }

    #[Computed]
    public function hasMore(): bool
    {
        return $this->perPage < $this->total;
    }

    #[Computed]
    public function prospects()
    {
        $query = $this->filtered()
            // `slug` is the Team route key — omitting it from a constrained
            // eager load makes route() fail with "missing required parameter",
            // which looks like a null relation but is a missing column.
            ->with([
                'committedTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                'position:id,abbreviation',
            ]);

        /*
         * Unranked prospects sort LAST on every option, not first. Most of a
         * class carries no national rank at all, and `order by rank` alone puts
         * all of them at the top on MySQL, burying the ranked ones the screen
         * exists to show.
         *
         * Every sort falls through to rank then name, because `national_rank`
         * is not unique — two prospects share rank 2 in the current data — and
         * an unstable order shows the same person twice across two chunks.
         */
        match ($this->sort) {
            'grade' => $query->orderByRaw('grade is null, grade desc'),
            'name' => $query->orderBy('display_name'),
            'team' => $query->leftJoin('teams', 'teams.id', '=', 'recruits.committed_team_id')
                ->select('recruits.*')
                ->orderByRaw('teams.location is null, teams.location'),
            default => null,
        };

        return $query
            ->orderByRaw('national_rank is null, national_rank')
            ->orderBy('display_name')
            ->take($this->perPage)
            ->get();
    }

    /**
     * Team classes, ranked — see App\Support\RecruitingClasses for how, and for
     * why neither average nor total works on its own.
     *
     * Shared with the team page's Recruiting tab so the two cannot disagree
     * about a team's rank. It used to pull every signee into PHP to average in
     * a Collection, which was invisible at 25 rows and is 5,193 objects now.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function teamClasses(): array
    {
        return RecruitingClasses::forClass($this->year);
    }

}; ?>

<div class="flex flex-col gap-4">
    <h1 class="sr-only">Recruiting</h1>

    {{--
        Players / Team classes as underlined sub-tabs, mirroring /stats — the
        underline is this app's one idiom for "which list am I looking at",
        and the blue pills are reserved for filters WITHIN a list. The class
        select rides the same ruled row, far right, where a WHEN control
        always sits.
    --}}
    <x-plate
        :tabs="['players' => 'Players', 'teams' => 'Team classes']"
        :selected="$view"
        model="view"
        key-prefix="rview"
    >
        <x-slot:actions>
            <x-season-menu :years="$this->classes" :selected="$year" label="Recruiting class" class="shrink-0" />
        </x-slot:actions>
    </x-plate>

    @if ($view === 'players')
        {{-- One row: search plus compact text-button menus. Positions were a
             scrolling pill strip — 1,015px of pills in a 390px track — and
             nothing scrolls sideways except the week scroller, so the
             open-ended set lives in a menu that scrolls vertically. --}}
        <x-filter-bar
            :placeholder="$this->searchPlaceholder"
            :sorts="$this::SORTS"
            :sort="$sort"
            key-prefix="rsort"
        >
            @if ($this->positionItems !== [])
                <x-filter-menu
                    :items="$this->positionItems"
                    :selected="$position"
                    action="selectPosition"
                    label="Filter by position"
                    key-prefix="rpos"
                    class="shrink-0"
                />
            @endif

            <x-scope-filter :year="$this->scopeYear" :selected="$scope" :top25="false" class="shrink-0" />
        </x-filter-bar>

        @if ($this->prospects->isNotEmpty())
            {{-- Two columns at `xl` only. The row needs 516px to render its
                 high school and hometown without truncating, which a 621px
                 half-column at `xl` clears and a `lg` one would not — and the
                 sentinel arithmetic is the same as Players': halving a chunk's
                 ~3,200px push still clears the viewport plus its margin. --}}
            <div wire:loading.class="opacity-60 pointer-events-none" wire:target="view, year, scope, position, sort, q" class="motion-safe:transition-opacity -mt-1 grid gap-1.5 xl:grid-cols-2">
                @foreach ($this->prospects as $recruit)
                    {{-- `min-w-0` because this is a flex item, whose automatic
                         minimum size is its MIN-CONTENT width: the inner column
                         truncates, but truncation cannot help while the row is
                         free to grow to fit the longest high school and
                         hometown. It reached 516px inside a 343px track. --}}
                    <div class="flex min-w-0 items-center gap-3 rounded-lg border border-zinc-200 p-2.5 dark:border-zinc-800"
                         wire:key="r-{{ $recruit->id }}">
                        <span class="tabular w-8 shrink-0 text-right text-stat font-semibold text-zinc-400">
                            {{ $recruit->national_rank ?? '—' }}
                        </span>

                        <div class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate text-sm font-medium">{{ $recruit->display_name }}</span>
                            <span class="truncate text-micro text-zinc-500">
                                {{ collect([$recruit->position?->abbreviation, $recruit->high_school])->filter()->implode(' · ') }}
                            </span>

                            {{-- Its own line, never another `·` segment. Packed
                                 onto one, 45 of 50 rows clipped at 390px: the
                                 hometown is the first thing truncation eats and
                                 it took the high school with it. --}}
                            @if ($recruit->hometown())
                                <span class="truncate text-micro text-zinc-400">{{ $recruit->hometown() }}</span>
                            @endif
                        </div>

                        @if ($recruit->committedTeam)
                            <x-team-link :team="$recruit->committedTeam" label="abbr" size="xs"
                                         class="shrink-0 text-zinc-500" />
                        @else
                            <span class="shrink-0 text-micro text-zinc-400">{{ $recruit->status ?? 'Uncommitted' }}</span>
                        @endif

                        <span class="tabular w-8 shrink-0 text-right text-sm font-semibold">{{ $recruit->grade ?? '—' }}</span>
                    </div>
                @endforeach
            </div>

            {{-- Sentinel: `wire:intersect` fires it on scroll and `wire:click`
                 keeps it a real button, so the list still advances if the
                 observer never runs. --}}
            @if ($this->hasMore)
                <button
                    type="button"
                    wire:click="loadMore"
                    wire:intersect.margin.600px="loadMore"
                    wire:key="load-more"
                    class="rounded-lg border border-zinc-200 px-3 py-2.5 text-stat font-medium text-zinc-500 transition-colors hover:border-zinc-300 hover:text-zinc-900 data-loading:opacity-50 dark:border-zinc-800 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100"
                >
                    <span wire:loading.remove wire:target="loadMore">
                        Load more <span class="text-zinc-400">({{ number_format($this->total - $this->prospects->count()) }} left)</span>
                    </span>
                    <span wire:loading wire:target="loadMore">Loading…</span>
                </button>
            @endif
        @else
            <flux:callout icon="academic-cap">
                <flux:callout.heading>No prospects</flux:callout.heading>
                <flux:callout.text>Nothing matches for the {{ $year }} class.</flux:callout.text>
            </flux:callout>
        @endif
    @else
        <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800">
            <table class="w-full text-stat whitespace-nowrap">
                <thead>
                    <tr class="border-b border-zinc-200 text-micro uppercase tracking-wide text-zinc-500 dark:border-zinc-800">
                        <th scope="col" class="px-2 py-2 text-left font-medium">School</th>
                        <th scope="col" class="px-1.5 py-2 text-right font-medium">
                            <span aria-hidden="true">Sign</span>
                            <span class="sr-only">Signees</span>
                        </th>
                        <th scope="col" class="px-1.5 py-2 text-right font-medium">
                            <span aria-hidden="true">Avg</span>
                            <span class="sr-only">Average grade</span>
                        </th>
                        <th scope="col" class="px-2 py-2 text-right font-medium">
                            <span aria-hidden="true">Top</span>
                            <span class="sr-only">Best recruit's national rank</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->teamClasses as $row)
                        {{-- `newFromBuilder` rather than `make`: it is the
                             constructor Eloquent uses for database rows, so it
                             bypasses fillable entirely. The model is built HERE
                             and never cached — a cached Eloquent model comes
                             back as __PHP_Incomplete_Class. --}}
                        @php $team = (new App\Models\Team)->newFromBuilder($row['team']); @endphp

                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60"
                            wire:key="tc-{{ $team->id }}">
                            {{-- `w-full max-w-0` is what makes the name truncate
                                 rather than the table scrolling: a cell sizes to
                                 its content's min-content width, and `truncate`
                                 sets nowrap. --}}
                            <td class="w-full max-w-0 px-2 py-2">
                                <x-team-link :team="$team" label="location" />
                            </td>
                            <td class="tabular px-1.5 py-2 text-right">{{ $row['signees'] }}</td>
                            <td class="tabular px-1.5 py-2 text-right font-semibold">{{ $row['average'] }}</td>
                            <td class="tabular px-2 py-2 text-right text-zinc-500">{{ $row['best'] ? '#'.$row['best'] : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-4 text-center text-zinc-500">No commitments recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
