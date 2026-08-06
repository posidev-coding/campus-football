<?php

use App\Models\Athlete;
use App\Models\AthleteSeasonStat;
use App\Models\Team;
use App\Models\TeamSeasonStat;
use App\Support\Ordinal;
use App\Support\Scope;
use App\Support\Stats\LeaderQuery;
use App\Support\Stats\StatCatalog;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Statistics, split by a Team/Players sub-toggle.
 *
 * This was two sections — "Team Stats" and "Player Stats" — whose components
 * were near-identical: the same year/scope/side properties, the same Top-25
 * rewrite, the same loop over StatCatalog. They differed only in which
 * LeaderQuery method they called and what a row looked like. Two of six League
 * slots for one idea, and "stats" became a place you had to guess at.
 *
 * TEAM leads because the question "how good is this team" is the one a league
 * stats screen is usually opened for; individual leaders are the follow-up.
 *
 * PLAYER numbers are derived from our own box scores, NOT from ESPN's national
 * leaders feed. That feed spans every division, only about half its top 100 is
 * FBS, and narrowing to a conference collapsed it — the MAC had FOUR players in
 * the national top 100 for passing yards. Ranking our own season aggregates
 * gives that conference 43 and numbers them 1..N.
 *
 * TEAM numbers are ranked WITHIN the selected scope. ESPN publishes a national
 * rank on every team stat and it is carried alongside for context, but it is
 * the wrong number to order by the moment a reader picks a conference — the
 * SEC's best offense should be row 1, not row 7.
 *
 * There is deliberately no Top 25 scope on either half. It filters TEAMS, which
 * is meaningful on a scoreboard and misleading here — "leading rusher among 25
 * teams" reads as if it were the national leader.
 */
new class extends Component
{
    public const TEAM = 'team';

    public const PLAYERS = 'players';

    #[Url]
    public string $view = self::TEAM;

    #[Url]
    public ?int $year = null;

    #[Url]
    public string $scope = Scope::FBS;

    #[Url]
    public string $side = StatCatalog::OFFENSE;

    public function mount(): void
    {
        $this->year ??= $this->latestYear();

        $this->normaliseScope();
    }

    /**
     * Top 25 is not offered here, so a bookmarked URL carrying it — or a user
     * arriving from Scores via wire:navigate with it still in the querystring —
     * would otherwise silently scope a leaderboard to 25 teams and present it
     * as if it were national.
     */
    public function updatedScope(): void
    {
        $this->normaliseScope();
    }

    /**
     * Switching halves re-resolves the year.
     *
     * The two views read DIFFERENT tables — team_season_stats comes from ESPN,
     * athlete_season_stats is aggregated from our box scores — so a year that
     * exists for one may not exist for the other. That is not hypothetical: box
     * scores are aggregated as a season finishes, while ESPN's team stats for
     * it can land later. Snapping to the newest available year shows numbers;
     * keeping the stale one shows an empty screen with no visible cause.
     *
     * Same move `rankings.blade.php` makes in updatedPoll(), for the same
     * reason: polls do not all run the same weeks.
     */
    public function updatedView(): void
    {
        if (! in_array($this->year, $this->years, true)) {
            $this->year = $this->years[0] ?? $this->year;
        }
    }

    private function normaliseScope(): void
    {
        if ($this->scope === Scope::TOP_25) {
            $this->scope = Scope::FBS;
        }
    }

    private function isTeamView(): bool
    {
        return $this->view === self::TEAM;
    }

    private function latestYear(): int
    {
        return $this->isTeamView()
            ? Cache::remember('stats:latest-year', 3600, fn () => TeamSeasonStat::max('season_year')
                ?? app(App\Services\CfbCalendar::class)->resultsYear())
            : Cache::remember('leaders:derived-year', 3600, fn () => AthleteSeasonStat::max('season_year')
                ?? app(App\Services\CfbCalendar::class)->resultsYear());
    }

    /**
     * The years the CURRENT half actually has, newest first.
     *
     * Cache keys are the ones each screen already used, so nothing needs a
     * cache flush to pick this change up.
     *
     * @return list<int>
     */
    #[Computed]
    public function years(): array
    {
        return $this->isTeamView()
            ? Cache::remember('stats:years', 3600, fn () => TeamSeasonStat::query()
                ->distinct()->orderByDesc('season_year')->pluck('season_year')->all())
            : Cache::remember('leaders:years', 3600, fn () => AthleteSeasonStat::query()
                ->distinct()->orderByDesc('season_year')->pluck('season_year')->all());
    }

    /** @return array<string, string> */
    #[Computed]
    public function sides(): array
    {
        return StatCatalog::sideLabels();
    }

    /**
     * Every leaderboard on the current side, grouped, with its rows resolved.
     *
     * @return list<array{group:string, boards:list<array<string, mixed>>}>
     */
    #[Computed]
    public function groups(): array
    {
        $team = $this->isTeamView();
        $groups = [];

        foreach (StatCatalog::groups($this->side, team: $team) as $group) {
            $boards = [];

            foreach (StatCatalog::boardsFor($this->side, $group, team: $team) as $board) {
                $rows = $team
                    ? LeaderQuery::teams($board, $this->year, $this->scope, limit: 5)
                    : LeaderQuery::players($board, $this->year, $this->scope, limit: 5);

                if ($rows !== []) {
                    $boards[] = ['meta' => $board, 'rows' => $rows];
                }
            }

            if ($boards !== []) {
                $groups[] = ['group' => $group, 'boards' => $boards];
            }
        }

        return $groups;
    }

    /**
     * Athletes for everything on screen, in one query.
     *
     * Resolved in bulk rather than per row: a side renders up to ten
     * leaderboards of five, and looking each one up individually would be 50
     * round trips for what one `whereIn` answers. Empty on the team view, which
     * has no athletes to resolve.
     */
    #[Computed]
    public function athletes()
    {
        if ($this->isTeamView()) {
            return collect();
        }

        $ids = $this->rowValues('athlete_id');

        return Athlete::whereIn('id', $ids)->get(['id', 'slug', 'display_name', 'short_name', 'headshot_url'])->keyBy('id');
    }

    /** Both halves show teams — as the row itself, or as the player's badge. */
    #[Computed]
    public function teams()
    {
        $ids = $this->rowValues('team_id');

        return Team::whereIn('id', $ids)
            ->get(['id', 'slug', 'display_name', 'short_display_name', 'abbreviation', 'logo', 'logo_dark'])
            ->keyBy('id');
    }

    private function rowValues(string $key)
    {
        return collect($this->groups)
            ->pluck('boards')->flatten(1)
            ->pluck('rows')->flatten(1)
            ->pluck($key)->filter();
    }
}; ?>

<div class="flex flex-col gap-4">
    <h1 class="sr-only">Stats</h1>

    {{--
        Sub-tabs left, filters right, one ruled row — the app's one shape for
        "which list am I looking at". NOT full-bleed: this plate sits in the
        screen's content column, and chrome bleeds while a control inside
        content does not. (The bleed variant belongs to hero-led screens like
        the team page.)
    --}}
    <x-plate
        :tabs="['team' => 'Team', 'players' => 'Players']"
        :selected="$view"
        model="view"
        key-prefix="statsview"
    >
        <x-slot:actions>
            {{-- One "which numbers" pair, season outermost, sitting on the
                 rule the way a tab bar's controls do. --}}
            <x-scope-filter :year="$year" :selected="$scope" :top25="false" class="shrink-0" />

            <x-season-menu :years="$this->years" :selected="$year" class="shrink-0" />
        </x-slot:actions>
    </x-plate>

    {{--
        Categories as a full-width gutter, not a third underline: the plate
        above and the section strip above THAT are both underlined, and a
        third treatment in one column would read as navigation. The `block`
        variant divides the row equally — the shape for a categorical
        taxonomy of three or four.
    --}}
    <x-gutter-tabs
        :items="$this->sides"
        :selected="$side"
        model="side"
        label="Stat category"
        key-prefix="side"
        variant="block"
        class="-mt-1"
    />

    @forelse ($this->groups as $group)
        {{-- `$view` in the key as well as `$side`: without it Livewire morphs a
             team board into the player board at the same index, and the row
             contents disagree with the header. --}}
        <div class="flex flex-col gap-2" wire:key="grp-{{ $view }}-{{ $side }}-{{ $group['group'] }}">
            <flux:subheading>{{ $group['group'] }}</flux:subheading>

            <div class="grid gap-3 lg:grid-cols-2">
                @foreach ($group['boards'] as $board)
                    <div class="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-800"
                         wire:key="brd-{{ $view }}-{{ $board['meta']['category'] }}-{{ $board['meta']['stat'] }}">
                        <header class="flex items-baseline justify-between gap-2 border-b border-zinc-100 px-3 py-2 dark:border-zinc-800/60">
                            <h3 class="text-stat font-semibold">{{ $board['meta']['label'] }}</h3>

                            @if (isset($board['meta']['min']))
                                {{-- Stated, not hidden: a rate leaderboard with no
                                     floor is won by whoever attempted once. Player
                                     boards only — team rates have no qualifier. --}}
                                <span class="text-micro text-zinc-400">
                                    min {{ $board['meta']['min'][1] }}
                                </span>
                            @endif
                        </header>

                        <ol class="flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800/60">
                            @foreach ($board['rows'] as $row)
                                <li class="flex items-center gap-2 px-3 py-1.5">
                                    <span class="tabular w-4 shrink-0 text-right text-micro font-semibold text-zinc-400">
                                        {{ $row['rank'] }}
                                    </span>

                                    @if ($view === 'team')
                                        <x-team-link :team="$this->teams->get($row['team_id'])" label="short"
                                                     size="xs" class="min-w-0 flex-1" />

                                        {{-- ESPN's national rank, kept as context when
                                             the scope is narrower than the division. --}}
                                        @if ($row['national'] && $scope !== Scope::FBS)
                                            <span class="tabular shrink-0 text-micro text-zinc-400">
                                                {{ Ordinal::of($row['national']) }}
                                            </span>
                                        @endif
                                    @else
                                        @php $athlete = $this->athletes->get($row['athlete_id']); @endphp

                                        <div class="flex min-w-0 flex-1 items-center gap-1.5">
                                            @if ($athlete)
                                                <x-player-link :athlete="$athlete" size="xs" />
                                            @else
                                                <span class="truncate text-micro text-zinc-500">Unknown player</span>
                                            @endif

                                            <x-team-link :team="$this->teams->get($row['team_id'])" label="abbr"
                                                         size="xs" :logo="false" class="shrink-0 text-zinc-400" />
                                        </div>
                                    @endif

                                    <span class="tabular shrink-0 text-stat font-semibold">{{ $row['display'] }}</span>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <flux:callout icon="chart-bar">
            <flux:callout.heading>No statistics</flux:callout.heading>
            <flux:callout.text>
                @if ($view === 'team')
                    Nothing published for {{ $year }} yet.
                @else
                    Nothing derived for {{ $year }} yet. Run <code>php artisan cfb:aggregate</code>.
                @endif
            </flux:callout.text>
        </flux:callout>
    @endforelse
</div>
