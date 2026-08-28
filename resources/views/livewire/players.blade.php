<?php

use App\Models\AthleteTeamSeason;
use App\Models\Position;
use App\Support\Scope;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The player index — every player on the current rosters.
 *
 * Built on `athlete_team_seasons` rather than `athletes`, because a player's
 * team, jersey, position and class are all facts about a SEASON. The Athlete
 * model deliberately has no `team_id` for that reason.
 *
 * There is NO season selector, and that is the screen's shape rather than an
 * omission. ESPN publishes only the CURRENT roster: earlier seasons hold rows
 * derived from box scores, which carry a jersey and a team and no position, so
 * a 2024 view would be a name list with the position filter switched off. This
 * screen is "who is on a roster now"; a player's history lives on their page.
 *
 * The name filter is a PREFIX, matching Search::players() and the model's own
 * `#[SearchUsingPrefix]`. "Smith" finds every Smith through `last_name`;
 * "mith" finds nobody. That is a real limitation and the right one: the table
 * is 34,836 rows, a prefix rides the btree, and a screen that matched
 * differently from the search bar above it would read as a bug.
 */
new class extends Component
{
    /** Below this the position filter cannot mean anything — see positions(). */
    private const POSITION_COVERAGE = 0.5;

    /** Rows per load — the initial page and each subsequent chunk. */
    private const CHUNK = 50;

    /**
     * How many rows are on screen. Grows; never in the URL.
     *
     * Deliberately not `#[Url]`: a shared link carrying `perPage=13580` would
     * hand any visitor a way to render the whole division in one request. A
     * link resets to the first chunk, which is the honest behaviour for an
     * infinitely-scrolled list anyway — there is no page number to return to.
     */
    public int $perPage = self::CHUNK;

    #[Url]
    public string $q = '';

    #[Url]
    public string $scope = Scope::FBS;

    /** An ABBREVIATION, never a position id — see positions(). */
    #[Url]
    public string $position = '';

    /**
     * LAST by default, which is how a roster, a box score and a depth chart are
     * all listed — and it agrees with what the name filter matches, so typing
     * "Smith" and sorting agree about which half of a name is the handle.
     *
     * The choice is NOT about cost. Every option is the same price, measured:
     * 118ms / 91ms / 116ms for a deep page of 13,580. The query is driven from
     * `team_seasons` into `athlete_team_seasons`, so ordering on a column of
     * `athletes` is a filesort whichever one it is — `athletes_last_name_index`
     * serves the name FILTER, never this sort.
     */
    #[Url]
    public string $sort = 'last';

    /**
     * Direction rides IN the value rather than in a second property.
     *
     * Only surname sorting has two useful directions — "teams, Z first" and
     * "first names, Z first" answer no question a reader has — so a separate
     * `$direction` would be a control that means nothing for two of the four
     * options, which is the state this codebase already avoids elsewhere. One
     * value keeps every option directly clickable instead of hiding the
     * reverse behind a second click on the option already selected.
     *
     * @var array<string, string>
     */
    public const SORTS = [
        'name' => 'Name',
        'last' => 'Last (A–Z)',
        'last_desc' => 'Last (Z–A)',
        'team' => 'Team',
    ];

    /**
     * Any filter change collapses back to the first chunk.
     *
     * Without this, narrowing from 13,580 players to one conference keeps
     * however many rows were already on screen, so a reader scrolls a long way
     * through a list they thought they had just shortened. Sorting is in the
     * list for the same reason: a re-sorted list is a different set of people.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'scope', 'position', 'sort'], true)) {
            $this->perPage = self::CHUNK;
        }
    }

    /**
     * Show one more chunk.
     *
     * Grows a LIMIT rather than appending a page into an island. An island
     * would re-render only the new rows, which is cheaper — but islands are
     * SKIPPED on a parent re-render and replaced wholesale when forced to run,
     * so a filter change would either leave the list stale or collapse it to
     * whatever the last chunk happened to be. Re-rendering everything is the
     * slower option and the one that cannot show the wrong rows.
     */
    public function loadMore(): void
    {
        if ($this->perPage < $this->total) {
            $this->perPage += self::CHUNK;
        }
    }

    /**
     * Ignore anything not on the menu.
     *
     * Needed in BOTH places: `#[Url]` hydrates from the querystring at mount
     * without ever firing the update hook, so a bookmarked `?sort=nonsense`
     * would reach the query builder on the first load and only be corrected if
     * the user happened to touch the control.
     */
    public function mount(): void
    {
        $this->normaliseSort();
    }

    public function updatedSort(): void
    {
        $this->normaliseSort();
    }

    private function normaliseSort(): void
    {
        if (! array_key_exists($this->sort, self::SORTS)) {
            $this->sort = 'last';
        }
    }

    /** Picking the active position again clears it, like a toggle. */
    public function selectPosition(string $abbreviation): void
    {
        $this->position = $this->position === $abbreviation ? '' : $abbreviation;

        $this->perPage = self::CHUNK;
    }

    /**
     * The newest season that has a roster.
     *
     * Not resultsYear(), which points at the last season with GAMES and is a
     * year behind all summer — a roster exists for the season being approached
     * long before it is played.
     */
    #[Computed]
    public function year(): int
    {
        return Cache::remember(
            'players:roster-year',
            3600,
            fn () => (int) (AthleteTeamSeason::max('season_year')
                ?? app(App\Services\CfbCalendar::class)->currentYear())
        );
    }

    /**
     * Position options, as ABBREVIATIONS, in depth-chart order.
     *
     * Keyed on the abbreviation and not on `position_id` because ESPN's ids
     * duplicate: among positions with 2026 rows, `LS` resolves to TWO of them
     * (39 with 256 players, 78 with 13). A control built from ids renders "LS"
     * twice and each entry silently hides the other's players.
     *
     * ORDER still matters in a menu: a reader scans downward, and alphabetical
     * buried QB seventeenth behind C, CB, DB, DE... Sorted by ESPN's own
     * `position_group` — offense, defense, special teams, the order every
     * roster page uses including our own — then by squad size within each.
     * Derived rather than a hardcoded list, so a position ESPN adds lands in
     * its group instead of being dropped.
     *
     * Empty when the season's rows do not actually carry positions, and the
     * test is COVERAGE rather than presence: a handful of positioned rows would
     * build a filter that looks complete and matches 3% of the roster.
     *
     * @return list<string>
     */
    #[Computed]
    public function positions(): array
    {
        return Cache::remember("players:positions:{$this->year}", 3600, function () {
            $season = AthleteTeamSeason::where('season_year', $this->year);

            $total = (clone $season)->count();
            $positioned = (clone $season)->whereNotNull('position_id')->count();

            if ($total === 0 || $positioned / $total < self::POSITION_COVERAGE) {
                return [];
            }

            $rank = ['offense' => 0, 'defense' => 1, 'special_teams' => 2];

            return DB::table('athlete_team_seasons as ats')
                ->join('positions as p', 'p.id', '=', 'ats.position_id')
                ->where('ats.season_year', $this->year)
                ->groupBy('p.abbreviation', 'ats.position_group')
                // `stored` is reserved in MySQL 8, and `count` reads badly —
                // alias it plainly.
                ->selectRaw('p.abbreviation as abbreviation, ats.position_group as grp, count(*) as players')
                ->get()
                // One abbreviation can appear under two ids and therefore two
                // rows; fold them and take the group most of its players sit in.
                ->groupBy('abbreviation')
                ->map(fn ($rows) => [
                    'abbreviation' => $rows->first()->abbreviation,
                    'grp' => $rows->sortByDesc('players')->first()->grp,
                    'players' => $rows->sum('players'),
                ])
                ->sortBy(fn (array $p) => [$rank[$p['grp']] ?? 9, -$p['players']])
                ->pluck('abbreviation')
                ->values()
                ->all();
        });
    }

    /**
     * Every row the current filters admit, unordered and unlimited.
     *
     * Shared by the row fetch and the total, so the two cannot disagree about
     * what is being counted — a "load more" that stays visible on an exhausted
     * list, or hides on a full one, is a filter drift between two queries.
     */
    private function filtered(): Builder
    {
        // Joined rather than sorted through a relation: every column the list
        // can be ordered by lives on another table.
        //
        // null means "do not filter"; [] means "filter to nothing". Conflating
        // them shows the whole league where the reader asked for a conference
        // that has no teams.
        $teamIds = Scope::teamIds($this->scope, $this->year);

        $positionIds = $this->position === ''
            ? []
            : Position::where('abbreviation', $this->position)->pluck('id')->all();

        return AthleteTeamSeason::query()
            // Joined rather than sorted through a relation: every column the
            // list can be ordered by lives on another table.
            // Joined here because the NAME FILTER needs it. The sort's own
            // joins belong to players() — this builder is also counted, and a
            // join added in both places is a duplicate alias, not a no-op.
            ->join('athletes', 'athletes.id', '=', 'athlete_team_seasons.athlete_id')
            ->where('athlete_team_seasons.season_year', $this->year)
            // Driving from team ids is what makes this fast: there is no index
            // leading with `season_year`, but there is one on
            // (team_id, season_year, position_group).
            ->when($teamIds !== null, fn ($q) => $q->whereIn('athlete_team_seasons.team_id', $teamIds))
            ->when($positionIds !== [], fn ($q) => $q->whereIn('athlete_team_seasons.position_id', $positionIds))
            // Every word has to match a name, in any order — "aguilar joey"
            // filters the same as "joey aguilar". Prefix on both halves, which
            // is what keeps the two name indexes usable; AND only, because a
            // filter that widens when it fails to match is one nobody trusts.
            ->when($this->q !== '', fn ($q) => Search::everyTerm($q, [
                'athletes.display_name',
                'athletes.last_name',
            ], $this->q));
    }

    /**
     * The position filter's menu items.
     *
     * The trigger shows only the current selection ("All positions", "QB"),
     * so each menu row carries the descriptive plural beside the abbreviation
     * — "QB · Quarterbacks" — where one exists. `descriptive()` first because
     * ESPN duplicates position ids and the junk twin's name IS its
     * abbreviation; `unique()` after that sort keeps the useful one.
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

    /** How many rows the filters admit in total — drives "is there more?". */
    #[Computed]
    public function total(): int
    {
        /*
         * Cached per FILTER TUPLE: the count cannot change between two
         * loadMore taps on the same filters, but the COUNT over 34,836
         * athletes re-ran on every one. The tuple key means a filter
         * change is simply a different key — no invalidation to forget.
         */
        return Cache::remember(
            'players:total:'.md5(implode('|', [$this->q, $this->scope, $this->position, $this->year])),
            300,
            fn () => $this->filtered()->count(),
        );
    }

    #[Computed]
    public function hasMore(): bool
    {
        return $this->perPage < $this->total;
    }

    /**
     * "Search players", or the position once one is picked.
     *
     * The filter's trigger shows only an abbreviation, so naming the position
     * here says what the list is showing without spending a row of the page on
     * a heading that only ever repeats the filter.
     *
     * Falls back to the bare abbreviation if the row carries no usable name,
     * which still reads as a placeholder rather than as nothing.
     */
    #[Computed]
    public function searchPlaceholder(): string
    {
        if ($this->position === '') {
            return 'Search players…';
        }

        $name = Position::where('abbreviation', $this->position)
            ->descriptive()
            ->first()
            ?->pluralName();

        return 'Search '.($name ?: $this->position).'…';
    }

    #[Computed]
    public function players()
    {
        // Name is first-then-last, and Team is by the place the row prints.
        // Both ascending only; Last is the one that carries a direction.
        [$column, $direction] = match ($this->sort) {
            'name' => ['athletes.first_name', 'asc'],
            'last_desc' => ['athletes.last_name', 'desc'],
            'team' => ['teams.location', 'asc'],
            default => ['athletes.last_name', 'asc'],
        };

        return $this->filtered()
            ->with([
                // `location` because the row prints placeName(). Omit it from a
                // constrained eager load and every team silently falls back to
                // its display name, which reads as a design decision rather
                // than a missing column.
                'athlete:id,slug,display_name,headshot_url,birth_city,birth_state',
                'team:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                'position:id,abbreviation',
            ])
            // Only the team sort needs this one, and it is a LEFT join so a row
            // whose team went missing sorts to the front rather than vanishing
            // from a list it belongs in.
            ->when($this->sort === 'team', fn ($q) => $q->leftJoin(
                'teams', 'teams.id', '=', 'athlete_team_seasons.team_id'
            ))
            ->select('athlete_team_seasons.*')
            ->orderBy($column, $direction)
            // Ties break on whatever half of the name is left, IN THE SAME
            // DIRECTION — a list reversed at the top and ascending underneath
            // is not reversed, it is two sorts. Skipped where the tiebreak is
            // already the primary, so nothing is ordered by twice.
            ->when($column !== 'athletes.last_name',
                fn ($q) => $q->orderBy('athletes.last_name', $direction))
            ->when($column !== 'athletes.first_name',
                fn ($q) => $q->orderBy('athletes.first_name', $direction))
            ->take($this->perPage)
            ->get();
    }
}; ?>

<div class="flex flex-col gap-4">
    <h1 class="sr-only">Players</h1>

    {{--
        One row of chrome: search owns it, and the position and scope menus
        beside it are compact text buttons. Positions were once a scrolling
        pill strip here — 1,015px of pills in a 390px track — and the app-wide
        rule is that nothing scrolls sideways except the week scroller, so an
        open-ended set like 22 positions lives in a menu that scrolls
        VERTICALLY instead.

        The position filter renders only where the season's rows actually
        carry positions — see positions(). A box-score-derived season would
        offer a filter matching 3% of the roster.
    --}}
    <x-filter-bar
        :placeholder="$this->searchPlaceholder"
        :sorts="$this::SORTS"
        :sort="$sort"
        key-prefix="sort"
    >
        @if ($this->positionItems !== [])
            <x-filter-menu
                :items="$this->positionItems"
                :selected="$position"
                action="selectPosition"
                label="Filter by position"
                key-prefix="pos"
                class="shrink-0"
            />
        @endif

        <x-scope-filter :year="$this->year" :selected="$scope" :top25="false" class="shrink-0" />
    </x-filter-bar>

    @if ($this->players->isNotEmpty())
        {{-- Two columns at `xl` only, and the ceiling is arithmetic rather
             than taste: a chunk is 50 rows at ~64px, so one load pushes the
             sentinel ~3,200px down, far past any viewport plus its 600px
             margin. Two columns halve that to ~1,600px, which still clears.
             Three would leave ~1,067px and let the observer re-enter before
             the guard settles. --}}
        <div wire:loading.class="opacity-60 pointer-events-none" wire:target="q, scope, position, sort" class="motion-safe:transition-opacity -mt-1 grid gap-1.5 xl:grid-cols-2">
            @foreach ($this->players as $row)
                {{-- The season is passed explicitly rather than left to default
                     to `latestSeason`: that would lazy-load a relation per row,
                     and lazy loading is disabled app-wide, so fifty rows would
                     be a 500 rather than an N+1. --}}
                <x-search.player-row
                    :athlete="$row->athlete"
                    :season="$row"
                    logo
                    wire:key="p-{{ $row->athlete_id }}"
                />
            @endforeach
        </div>

        {{--
            The sentinel. `wire:intersect` fires it on scroll and `wire:click`
            keeps it a real button, so the list still advances if the observer
            never runs — a disabled-JS visitor, or a browser that throttles
            observers in a background tab, otherwise sees 50 of 13,580 with no
            way forward.

            `.margin.600px` starts the fetch before the button is on screen, so
            the rows are usually there by the time the reader reaches them.

            It cannot run away: a chunk is 50 rows at 64px, so loading one
            pushes this ~3,200px down — far past any viewport plus the margin —
            and `wire:intersect` only fires on ENTERING the viewport. The guard
            in loadMore() is the belt to that braces.
        --}}
        @if ($this->hasMore)
            <button
                type="button"
                wire:click="loadMore"
                wire:intersect.margin.600px="loadMore"
                wire:key="load-more"
                class="rounded-lg border border-zinc-200 px-3 py-2.5 text-stat font-medium text-zinc-500 transition-colors hover:border-zinc-300 hover:text-zinc-900 data-loading:opacity-50 dark:border-zinc-800 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100"
            >
                <span wire:loading.remove wire:target="loadMore">
                    Load more <span class="text-zinc-400">({{ number_format($this->total - $this->players->count()) }} left)</span>
                </span>
                <span wire:loading wire:target="loadMore">Loading…</span>
            </button>
        @endif
    @else
        <flux:callout icon="user-group">
            <flux:callout.heading>No players</flux:callout.heading>
            <flux:callout.text>Nothing matches those filters.</flux:callout.text>
        </flux:callout>
    @endif
</div>
