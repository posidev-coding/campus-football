<?php

use App\Jobs\FetchGameSummary;
use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\AthleteSeasonStat;
use App\Models\Game;
use App\Models\GameDrive;
use App\Models\TeamSeason;
use App\Models\TeamSeasonStat;
use App\Services\CfbCalendar;
use App\Services\Espn\Sync\SyncGameSummary;
use App\Services\Stats\AggregateAthleteStats;
use App\Support\GameRanks;
use App\Support\TeamPalette;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A single game — one shell, three states.
 *
 * The first tab IS the state: Preview before kickoff (matchup predictor,
 * comparison, leaders, last meetings), Live during (situation, win
 * probability, the drive feed), Recap after (line score, leaders, the story
 * of the game). Box, Scoring, Drives and Odds ride behind whichever leads.
 *
 * Rendering is a pure database read — this page can no longer CAUSE a
 * synchronous ESPN request. Viewing a live game QUEUES a summary refresh
 * (the athlete game-log pattern): the job is unique on the game and
 * re-checks staleness before fetching, so a hundred viewers plus the
 * gameday sweep collapse into at most one request per 60s window, and no
 * page request ever blocks on a 544 KB fetch. Between views, the two-minute
 * live sweep (cfb:summaries:live) keeps every in-progress game hydrated.
 *
 * Drives are the exception to "load everything": ~306 KB of JSON in their
 * own table precisely so a page view does not read them. They are queried
 * only while a tab that shows them is active — the split that took 1.4 GB
 * out of the hot path must not be quietly undone by an eager load.
 */
new class extends Component
{
    /**
     * Half of GameSummary's own sixty-second staleness window — the moment a
     * forced fetch starts being worth its 544 KB.
     */
    private const REFRESH_OFFERED_AFTER = 30;

    public Game $game;

    #[Url]
    public string $tab = '';

    /** The Gameday sheet, and the ET day it is paging. */
    public bool $sheetOpen = false;

    public string $leagueDate = '';

    public function mount(Game $game): void
    {
        $this->game = $game->load([
            'homeTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark,color,alt_color',
            'awayTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark,color,alt_color',
            'venue',
            'week:id,name',
            'season:id,year',
        ]);

        $this->leagueDate = $this->game->kickoff_at
            ->setTimezone(config('cfb.timezone'))
            ->toDateString();

        $this->hydrateSummary();
        $this->normalizeTab();
    }

    /**
     * The right first tab opens itself, and a URL carried across a state
     * change (?tab=live on a game that has since gone final) resolves to the
     * new state's lead rather than an empty pane.
     */
    private function normalizeTab(): void
    {
        if (! array_key_exists($this->tab, $this->tabs)) {
            $this->tab = array_key_first($this->tabs);
        }
    }

    /**
     * Queue a summary refresh when the stored copy is due one.
     *
     * Dispatched, never fetched inline — the fetch is 544 KB plus a write
     * transaction, and holding a page request open for it put the slow path
     * on the one screen people refresh most. The job's uniqueness and its
     * own staleness re-check absorb every concurrent viewer.
     */
    private function hydrateSummary(): void
    {
        // Pregame there is nothing to fetch: the payload has no box score
        // yet, and the old inline refresh burned one 544 KB request a minute
        // on every upcoming game somebody left open.
        if ($this->game->status === 'pre' && ! $this->game->completed) {
            return;
        }

        if (app(SyncGameSummary::class)->isStale($this->game)) {
            FetchGameSummary::dispatch($this->game->id)->onQueue('live');
        }
    }

    /**
     * Live games re-poll. Each poll re-reads our own database and re-queues
     * the refresh only once the 60s window has passed, so extra viewers cost
     * database reads rather than ESPN requests.
     */
    public function poll(): void
    {
        $this->game->refresh();
        $this->hydrateSummary();

        unset(
            $this->teamStats, $this->scoringPlays, $this->playerStats,
            $this->summary, $this->drives, $this->winProbability, $this->tabs,
            $this->canRefresh, $this->refreshAvailableIn,
        );

        // A whistle mid-visit: the Live tab just became Recap.
        $this->normalizeTab();
    }

    /**
     * Whether to offer a hand-asked refresh.
     *
     * The summary refetches itself at most once every sixty seconds
     * (GameSummary::isStale), and the page re-reads the database every thirty.
     * Offering the button at the HALFWAY mark means it only ever appears when
     * pressing it would genuinely expedite something: before then the stored
     * copy is newer than the window, and a forced fetch would spend a 544 KB
     * request to learn nothing.
     *
     * So it is hidden for the first half of each cycle and offered for the
     * second — and it only exists for a live game, because a final summary
     * cannot change and a pregame one does not exist.
     */
    #[Computed]
    public function canRefresh(): bool
    {
        if (! $this->isLive) {
            return false;
        }

        $syncedAt = $this->summary?->synced_at;

        // Never synced: the mount dispatch is either in flight or was dropped,
        // and asking again is free — the job is unique per game.
        return $syncedAt === null
            || $syncedAt->diffInSeconds(now()) >= self::REFRESH_OFFERED_AFTER;
    }

    /**
     * Seconds until a hand-asked refresh becomes worth making — what the
     * countdown ring depletes over. Zero once it is available.
     *
     * The ring is driven client-side because the page only re-renders every
     * thirty seconds, and a countdown that moved twice a minute would not be
     * a countdown. The server still owns the DECISION (canRefresh), so the
     * two can disagree by at most the second the tick lands on.
     */
    #[Computed]
    public function refreshAvailableIn(): int
    {
        if (! $this->isLive || $this->canRefresh) {
            return 0;
        }

        $syncedAt = $this->summary?->synced_at;

        return $syncedAt === null
            ? 0
            : max(0, self::REFRESH_OFFERED_AFTER - (int) $syncedAt->diffInSeconds(now()));
    }

    /**
     * Force a fetch past the staleness check.
     *
     * `force: true` is the whole point — the button is only offered when the
     * stored copy is NOT yet stale enough to refetch on its own, so an
     * unforced dispatch would re-check staleness and no-op.
     *
     * Not named refresh(): Livewire already answers to `$refresh`, and a
     * helper sharing a framework name is a fatal waiting to happen.
     */
    public function forceRefresh(): void
    {
        if (! $this->canRefresh) {
            return;
        }

        FetchGameSummary::dispatch($this->game->id, force: true)->onQueue('live');

        // The row itself may already have moved; re-read what we hold rather
        // than waiting for the next poll tick.
        $this->poll();
    }

    public function shiftLeagueDay(int $days): void
    {
        $this->leagueDate = CarbonImmutable::parse($this->leagueDate)
            ->addDays($days)
            ->toDateString();

        unset($this->leagueSlate);
    }

    public function updatedSheetOpen(): void
    {
        unset($this->leagueSlate);
    }

    /** pre | live | final */
    #[Computed]
    public function state(): string
    {
        return match (true) {
            $this->game->completed => 'final',
            $this->game->status === 'in' => 'live',
            default => 'pre',
        };
    }

    #[Computed]
    public function isLive(): bool
    {
        return $this->state === 'live';
    }

    /**
     * A game that has not kicked off has exactly ONE tab, so it renders no
     * strip at all: the preview is a single scrolling screen with the odds
     * folded in at the top. Everything a pregame reader wants is a scroll
     * rather than a tap, and a two-item strip whose second item is one table
     * is a control charging for something the page can just show.
     *
     * Odds keep their own tab once a game is under way, where the preview's
     * scroll belongs to the box score instead.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function tabs(): array
    {
        if ($this->state === 'pre') {
            return ['preview' => 'Preview'];
        }

        $tabs = $this->state === 'live' ? ['live' => 'Live'] : ['recap' => 'Recap'];

        $tabs['box'] = 'Box';

        if ($this->scoringPlays->isNotEmpty()) {
            $tabs['scoring'] = 'Scoring';
        }

        if ($this->hasDrives) {
            $tabs['drives'] = 'Drives';
        }

        $tabs['odds'] = 'Odds';

        return $tabs;
    }

    #[Computed]
    public function summary()
    {
        return $this->game->summary()->first();
    }

    /** Keyed by team id so the two sides can be laid out against each other. */
    #[Computed]
    public function teamStats()
    {
        return $this->game->teamStats()->get()->keyBy('team_id');
    }

    #[Computed]
    public function scoringPlays()
    {
        return $this->game->scoringPlays()
            ->with('team:id,slug,abbreviation,short_display_name,logo,logo_dark')
            ->inOrder()
            ->get();
    }

    /**
     * Player box score, grouped by team then category.
     *
     * @return \Illuminate\Support\Collection
     */
    #[Computed]
    public function playerStats()
    {
        return AthleteGameStat::query()
            ->with('athlete:id,slug,display_name,short_name,headshot_url')
            ->where('game_id', $this->game->id)
            ->get()
            ->groupBy('team_id')
            ->map(fn ($rows) => $rows->groupBy('category'));
    }

    /**
     * The two sides, away first — the order a scoreboard is read in.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function sides(): array
    {
        return [
            ['team' => $this->game->awayTeam, 'score' => $this->game->away_score, 'rank' => $this->ranks['away'], 'record' => $this->game->away_record, 'line' => $this->game->away_line_scores, 'timeouts' => $this->game->away_timeouts],
            ['team' => $this->game->homeTeam, 'score' => $this->game->home_score, 'rank' => $this->ranks['home'], 'record' => $this->game->home_record, 'line' => $this->game->home_line_scores, 'timeouts' => $this->game->home_timeouts],
        ];
    }

    /** @return array{home: ?int, away: ?int} */
    #[Computed]
    public function ranks(): array
    {
        return GameRanks::forGame($this->game);
    }

    /**
     * The chart pair — light mode only; dark neutralizes in CSS. Resolved
     * together so two same-colored teams cannot merge into one ring.
     *
     * @return array{string, string} [away, home]
     */
    #[Computed]
    public function chartColors(): array
    {
        if ($this->game->awayTeam === null || $this->game->homeTeam === null) {
            return ['#3f3f46', '#71717a'];
        }

        return TeamPalette::chartColors($this->game->awayTeam, $this->game->homeTeam);
    }

    #[Computed]
    public function hasDrives(): bool
    {
        return GameDrive::whereKey($this->game->id)->exists();
    }

    /**
     * The drive chart, ONLY while a tab that renders it is active. ~306 KB
     * of JSON — the exact payload the game_drives split keeps off every
     * other view of this page.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function drives(): array
    {
        if (! in_array($this->tab, ['drives', 'live'], true)) {
            return [];
        }

        return GameDrive::find($this->game->id)?->drives ?? [];
    }

    /**
     * Win probability, downsampled for the chart. ESPN publishes a point per
     * play (~175); sixty is indistinguishable at chart size and a third the
     * payload. The final point always survives — it is the story's ending.
     *
     * @return list<float> home win probability, 0-100
     */
    #[Computed]
    public function winProbability(): array
    {
        $points = collect($this->summary?->win_probability ?? [])
            ->map(fn ($point) => (float) ($point['homeWinPercentage'] ?? 0) * 100)
            ->values();

        if ($points->count() <= 60) {
            return $points->all();
        }

        $step = (int) ceil($points->count() / 60);

        $sampled = $points->filter(fn ($value, $index) => $index % $step === 0)->values();

        return $sampled->push($points->last())->all();
    }

    /**
     * Completed meetings between these two teams, newest first — from our
     * own games table, no feed involved.
     *
     * @return \Illuminate\Support\Collection<int, Game>
     */
    #[Computed]
    public function lastMeetings()
    {
        $home = $this->game->home_team_id;
        $away = $this->game->away_team_id;

        if ($home === null || $away === null) {
            return collect();
        }

        return Game::query()
            ->completed()
            ->whereKeyNot($this->game->id)
            // Both orders: home teams swap between meetings.
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('home_team_id', $home)->where('away_team_id', $away))
                ->orWhere(fn ($q) => $q->where('home_team_id', $away)->where('away_team_id', $home)))
            ->with([
                'homeTeam:id,slug,location,short_display_name,abbreviation,logo,logo_dark',
                'awayTeam:id,slug,location,short_display_name,abbreviation,logo,logo_dark',
            ])
            ->orderByDesc('kickoff_at')
            ->limit(5)
            ->get();
    }

    /**
     * Each side's recent completed games, oldest first, for the trend pills.
     * Across seasons deliberately: before week 1 the honest recent run is
     * last season's end, and a mid-season game's is its own.
     *
     * @return array<int, \Illuminate\Support\Collection<int, Game>>
     */
    #[Computed]
    public function trends(): array
    {
        $teamIds = array_filter([$this->game->home_team_id, $this->game->away_team_id]);

        if ($teamIds === []) {
            return [];
        }

        $recent = Game::query()
            ->completed()
            ->where(fn ($q) => $q->whereIn('home_team_id', $teamIds)->orWhereIn('away_team_id', $teamIds))
            ->with([
                'homeTeam:id,slug,location,short_display_name,abbreviation,logo,logo_dark',
                'awayTeam:id,slug,location,short_display_name,abbreviation,logo,logo_dark',
            ])
            ->orderByDesc('kickoff_at')
            ->limit(30)
            ->get();

        return collect($teamIds)
            ->mapWithKeys(fn (int $teamId) => [$teamId => $recent
                ->filter(fn (Game $g) => $g->home_team_id === $teamId || $g->away_team_id === $teamId)
                ->take(5)
                ->reverse()
                ->values()])
            ->all();
    }

    /**
     * Season stat comparison rows, both sides of each bar from one query.
     * Falls back through seasons the way the team page's stats tab does —
     * before kickoff the season being played has no numbers, and the most
     * recent season that does is the honest comparison.
     *
     * @return array{year: ?int, rows: list<array{label: string, away: array<string, mixed>, home: array<string, mixed>}>}
     */
    #[Computed]
    public function comparison(): array
    {
        $teamIds = array_filter([$this->game->away_team_id, $this->game->home_team_id]);

        if (count($teamIds) < 2) {
            return ['year' => null, 'rows' => []];
        }

        $year = TeamSeasonStat::whereIn('team_id', $teamIds)->max('season_year');

        if ($year === null) {
            return ['year' => null, 'rows' => []];
        }

        $stats = TeamSeasonStat::query()
            ->whereIn('team_id', $teamIds)
            ->where('season_year', $year)
            ->whereIn('category', ['passing', 'scoring', 'rushing', 'defensive'])
            ->get()
            ->groupBy('team_id');

        $read = function (int $teamId, string $category, string $stat) use ($stats): array {
            $row = $stats->get($teamId)?->firstWhere('category', $category);

            return $row?->stat($stat) ?? ['display' => null, 'value' => null, 'rank' => null, 'label' => ''];
        };

        $rows = collect([
            ['category' => 'scoring', 'stat' => 'totalPointsPerGame', 'label' => 'Points / Game'],
            ['category' => 'passing', 'stat' => 'yardsPerGame', 'label' => 'Yards / Game'],
            ['category' => 'passing', 'stat' => 'netPassingYardsPerGame', 'label' => 'Passing / Game'],
            ['category' => 'rushing', 'stat' => 'rushingYardsPerGame', 'label' => 'Rushing / Game'],
            ['category' => 'defensive', 'stat' => 'sacks', 'label' => 'Sacks'],
            ['category' => 'defensive', 'stat' => 'totalTackles', 'label' => 'Tackles'],
        ])
            ->map(fn (array $row) => [
                'label' => $row['label'],
                'away' => $read($this->game->away_team_id, $row['category'], $row['stat']),
                'home' => $read($this->game->home_team_id, $row['category'], $row['stat']),
            ])
            ->filter(fn (array $row) => $row['away']['value'] !== null || $row['home']['value'] !== null)
            ->values()
            ->all();

        return ['year' => (int) $year, 'rows' => $rows];
    }

    /**
     * Each side's season leaders — passing, rushing, receiving — the
     * "probable pitchers" of a football preview. Derived from our own
     * aggregates, whole year including bowls, same as every leaderboard.
     *
     * @return array{year: ?int, rows: list<array{label: string, away: ?array<string, mixed>, home: ?array<string, mixed>}>}
     */
    #[Computed]
    public function seasonLeaders(): array
    {
        $teamIds = array_filter([$this->game->away_team_id, $this->game->home_team_id]);

        if (count($teamIds) < 2) {
            return ['year' => null, 'rows' => []];
        }

        $year = AthleteSeasonStat::whereIn('team_id', $teamIds)->max('season_year');

        if ($year === null) {
            return ['year' => null, 'rows' => []];
        }

        $categories = [
            'passing' => ['stat' => 'passingYards', 'tds' => 'passingTouchdowns', 'label' => 'Passing'],
            'rushing' => ['stat' => 'rushingYards', 'tds' => 'rushingTouchdowns', 'label' => 'Rushing'],
            'receiving' => ['stat' => 'receivingYards', 'tds' => 'receivingTouchdowns', 'label' => 'Receiving'],
        ];

        $rows = AthleteSeasonStat::query()
            ->whereIn('team_id', $teamIds)
            ->where('season_year', $year)
            ->where('season_type', AggregateAthleteStats::FULL_SEASON)
            ->whereIn('category', array_keys($categories))
            ->get(['athlete_id', 'team_id', 'category', 'stats']);

        $leaders = [];

        foreach ($categories as $category => $meta) {
            foreach ($teamIds as $teamId) {
                $best = $rows
                    ->where('category', $category)
                    ->where('team_id', $teamId)
                    ->sortByDesc(fn ($r) => (float) ($r->stats[$meta['stat']] ?? 0))
                    ->first();

                if ($best !== null && (float) ($best->stats[$meta['stat']] ?? 0) > 0) {
                    $leaders[$category][$teamId] = [
                        'athlete_id' => $best->athlete_id,
                        'yards' => (float) ($best->stats[$meta['stat']] ?? 0),
                        'tds' => (float) ($best->stats[$meta['tds']] ?? 0),
                    ];
                }
            }
        }

        $athletes = Athlete::whereIn('id', collect($leaders)->flatten(1)->pluck('athlete_id'))
            ->get(['id', 'slug', 'display_name', 'short_name', 'headshot_url'])
            ->keyBy('id');

        $resolve = function (string $category, ?int $teamId) use ($leaders, $athletes): ?array {
            $entry = $leaders[$category][$teamId] ?? null;

            if ($entry === null) {
                return null;
            }

            return $entry + ['athlete' => $athletes->get($entry['athlete_id'])];
        };

        return [
            'year' => (int) $year,
            'rows' => collect($categories)
                ->map(fn (array $meta, string $category) => [
                    'label' => $meta['label'],
                    'away' => $resolve($category, $this->game->away_team_id),
                    'home' => $resolve($category, $this->game->home_team_id),
                ])
                ->filter(fn (array $row) => $row['away'] !== null || $row['home'] !== null)
                ->values()
                ->all(),
        ];
    }

    /**
     * The game's statistical leaders, from the summary payload ESPN already
     * computed — passing, rushing, receiving per side. Athletes we hold link
     * to their pages; ones we do not degrade to the payload's name, because
     * a box score names everyone but the roster only names this season.
     *
     * @return list<array{label: string, away: ?array<string, mixed>, home: ?array<string, mixed>}>
     */
    #[Computed]
    public function gameLeaders(): array
    {
        $payload = collect($this->summary?->leaders ?? []);

        if ($payload->isEmpty()) {
            return [];
        }

        $wanted = ['passingYards' => 'Passing', 'rushingYards' => 'Rushing', 'receivingYards' => 'Receiving'];

        $entries = [];

        foreach ($payload as $side) {
            $teamId = (int) data_get($side, 'team.id');

            foreach (data_get($side, 'leaders', []) as $category) {
                $name = data_get($category, 'name');

                if (! isset($wanted[$name])) {
                    continue;
                }

                $leader = data_get($category, 'leaders.0');

                if ($leader === null) {
                    continue;
                }

                $entries[$name][$teamId] = [
                    'athlete_id' => (int) data_get($leader, 'athlete.id'),
                    'name' => data_get($leader, 'athlete.displayName'),
                    'display' => data_get($leader, 'displayValue'),
                ];
            }
        }

        $athletes = Athlete::whereIn('id', collect($entries)->flatten(1)->pluck('athlete_id')->filter())
            ->get(['id', 'slug', 'display_name', 'short_name', 'headshot_url'])
            ->keyBy('id');

        $resolve = function (string $name, ?int $teamId) use ($entries, $athletes): ?array {
            $entry = $entries[$name][$teamId] ?? null;

            return $entry === null ? null : $entry + ['athlete' => $athletes->get($entry['athlete_id'])];
        };

        return collect($wanted)
            ->map(fn (string $label, string $name) => [
                'label' => $label,
                'away' => $resolve($name, $this->game->away_team_id),
                'home' => $resolve($name, $this->game->home_team_id),
            ])
            ->filter(fn (array $row) => $row['away'] !== null || $row['home'] !== null)
            ->values()
            ->all();
    }

    /** Articles the summary sync attached: the recap, then the reading list. */
    #[Computed]
    public function articles()
    {
        return $this->game->articles()
            ->with('teams:id,slug,abbreviation,short_display_name,logo,logo_dark')
            ->orderByDesc('published_at')
            ->get();
    }

    /**
     * The Gameday sheet: that ET day's slate, grouped by what the
     * viewer cares about — their teams, ranked matchups, this game's
     * conference(s), then the rest. Each game claimed by the FIRST group
     * that wants it, the same rule the scoreboard's floated block uses.
     *
     * Computed only while the sheet is open; a closed sheet costs nothing.
     *
     * @return list<array{label: string, games: list<Game>}>
     */
    #[Computed]
    public function leagueSlate(): array
    {
        if (! $this->sheetOpen) {
            return [];
        }

        $tz = config('cfb.timezone');
        $day = CarbonImmutable::parse($this->leagueDate, $tz);

        $games = Game::query()
            ->whereKeyNot($this->game->id)
            ->whereBetween('kickoff_at', [$day->startOfDay()->utc(), $day->endOfDay()->utc()])
            ->with([
                'homeTeam:id,slug,location,short_display_name,abbreviation,logo,logo_dark',
                'awayTeam:id,slug,location,short_display_name,abbreviation,logo,logo_dark',
            ])
            ->orderBy('kickoff_at')
            ->get();

        if ($games->isEmpty()) {
            return [];
        }

        $followed = auth()->user()?->followedTeams()->pluck('teams.id')->all() ?? [];

        $conferenceTeams = $this->conferenceTeamIds();

        $claimed = [];

        // Marked with a plain foreach, not an arrow fn: arrow functions
        // capture by VALUE, so `fn ($g) => $claimed[$g->id] = true` writes a
        // copy and every group re-claims the whole slate.
        $claim = function (callable $wants) use ($games, &$claimed): array {
            $taken = $games
                ->filter(fn (Game $g) => ! isset($claimed[$g->id]) && $wants($g))
                ->values();

            foreach ($taken as $game) {
                $claimed[$game->id] = true;
            }

            return $taken->all();
        };

        $groups = [
            ['label' => 'Your teams', 'games' => $claim(fn (Game $g) => array_intersect($followed, [$g->home_team_id, $g->away_team_id]) !== [])],
            ['label' => 'Top 25', 'games' => $claim(function (Game $g) {
                $ranks = GameRanks::forGame($g);

                return $ranks['home'] !== null || $ranks['away'] !== null;
            })],
            ['label' => 'Conference', 'games' => $claim(fn (Game $g) => array_intersect($conferenceTeams, array_filter([$g->home_team_id, $g->away_team_id])) !== [])],
            ['label' => 'Around the league', 'games' => $claim(fn () => true)],
        ];

        return array_values(array_filter($groups, fn (array $group) => $group['games'] !== []));
    }

    /**
     * Teams sharing a conference with either side, in the game's season —
     * membership is season-scoped, never a scalar on the team.
     *
     * @return list<int>
     */
    private function conferenceTeamIds(): array
    {
        $year = $this->game->season?->year ?? app(CfbCalendar::class)->scoreboardYear();

        $teamIds = array_filter([$this->game->home_team_id, $this->game->away_team_id]);

        if ($teamIds === []) {
            return [];
        }

        $conferences = TeamSeason::where('season_year', $year)
            ->whereIn('team_id', $teamIds)
            ->whereNotNull('conference_id')
            ->pluck('conference_id');

        if ($conferences->isEmpty()) {
            return [];
        }

        return TeamSeason::where('season_year', $year)
            ->whereIn('conference_id', $conferences)
            ->pluck('team_id')
            ->all();
    }
}; ?>

<div class="flex flex-col gap-4" @if ($this->isLive) wire:poll.30s.visible="poll" @endif>
    {{--
        The scorebug: sticky, so the score survives every scroll. Cancels the
        container's padding the way the scoreboard chrome does, wears the
        header's own surface, and rests exactly where it sticks
        (h-14 + 1px border from `sm` up; the top of the viewport at base).
        The sheet is NOT nested in here — backdrop-blur makes this the
        containing block for fixed descendants, the search-panel lesson.
    --}}
    <div class="sticky top-0 z-30 -mx-4 -mt-5 border-b border-zinc-200 bg-white/95 px-4 pt-4 pb-3 backdrop-blur sm:top-[calc(var(--spacing)*14+1px)] dark:border-zinc-800 dark:bg-zinc-950/95">
        {{--
            A navigation bar, not a caption: Done · Gameday · Scores.

            Three columns rather than a flex row, because the title is CENTERED
            on the screen and the two sides have different widths — a
            `justify-between` row would centre it only by accident.
        --}}
        <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
            {{-- Back to wherever they came from — a game is reached from six
                 screens, so naming any one of them would be wrong. --}}
            <button
                type="button"
                x-data="{
                    done() {
                        // Depth, not history.length — the latter counts the
                        // blank new-tab page, so a shared link opened in a new
                        // tab would send the reader out of the app entirely.
                        window.cfbAppDepth > 1
                            ? window.history.back()
                            : Livewire.navigate(@js(route('scoreboard')));
                    },
                }"
                x-on:click="done()"
                class="justify-self-start rounded-md px-1.5 py-1 text-sm font-medium transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-800"
            >Back</button>

            {{-- The slate, one tap from the scorebug, exactly as MLB does it —
                 including the chevron flipping while the sheet is up. --}}
            <button
                type="button"
                wire:click="$set('sheetOpen', true)"
                class="flex items-center gap-1 justify-self-center rounded-md px-1.5 py-1 text-sm font-semibold transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-800"
            >
                Gameday
                @if ($sheetOpen)
                    <flux:icon.chevron-up variant="micro" class="size-4 text-zinc-400" />
                @else
                    <flux:icon.chevron-down variant="micro" class="size-4 text-zinc-400" />
                @endif
            </button>

            {{--
                The cooldown made visible: a blue ring that empties over the
                thirty seconds a forced fetch would be wasted in, then the word
                Refresh once it would actually expedite something.

                Keyed on the sync timestamp so a landed fetch REPLACES this
                node — Livewire's morph preserves Alpine state, so without a
                changing key the countdown would keep draining from wherever
                the last cycle left it instead of restarting at full.

                The slot itself is always in the grid, so the title never
                shifts as the control changes shape.
            --}}
            <div class="justify-self-end" wire:key="refresh-{{ $this->summary?->synced_at?->timestamp ?? 0 }}">
                @if ($this->isLive)
                    <div
                        x-data="{
                            remaining: @js($this->refreshAvailableIn),
                            total: @js($this->refreshAvailableIn ?: 1),
                            timer: null,
                            start() {
                                if (this.remaining <= 0) return;
                                this.timer = setInterval(() => {
                                    this.remaining = Math.max(0, this.remaining - 1);
                                    if (this.remaining === 0) this.stop();
                                }, 1000);
                            },
                            stop() {
                                if (this.timer) clearInterval(this.timer);
                                this.timer = null;
                            },
                        }"
                        x-init="start()"
                        x-on:beforeunload.window="stop()"
                    >
                        {{-- Emptying, not filling: the ring is time you still
                             have to wait, so it should be running out. --}}
                        <svg
                            x-show="remaining > 0"
                            x-cloak
                            viewBox="0 0 24 24"
                            class="size-5 -rotate-90"
                            aria-hidden="true"
                        >
                            <circle cx="12" cy="12" r="9" fill="none" stroke-width="2.5"
                                    class="stroke-zinc-200 dark:stroke-zinc-700" />
                            <circle cx="12" cy="12" r="9" fill="none" stroke-width="2.5" stroke-linecap="round"
                                    class="stroke-blue-500 transition-[stroke-dashoffset] duration-1000 ease-linear motion-reduce:transition-none"
                                    stroke-dasharray="56.55"
                                    :style="`stroke-dashoffset: ${56.55 * (1 - remaining / total)}`"
                            />
                        </svg>

                        <button
                            type="button"
                            x-show="remaining <= 0"
                            wire:click="forceRefresh"
                            class="rounded-md px-1.5 py-1 text-sm font-medium transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            wire:loading.attr="disabled"
                            wire:target="forceRefresh"
                        >Refresh</button>
                    </div>
                @endif
            </div>
        </div>

        {{-- The bowl or playoff name is the game's IDENTITY — "College Football
             Playoff National Championship" — and the only thing separating a
             playoff game from any other bowl, so it keeps a line of its own
             rather than losing its place to the nav row. --}}
        @if ($game->note)
            <p class="mt-1 truncate text-center text-micro font-medium text-zinc-500">{{ $game->note }}</p>
        @endif

        <div class="mt-2 flex items-center gap-2">
            @foreach ($this->sides as $index => $side)
                @php
                    $winner = $game->winnerTeamId();
                    $lost = $game->completed && $winner !== null && $winner !== $side['team']?->id;
                    $possession = $this->isLive && $side['team'] && $game->possession_team_id === $side['team']->id;
                @endphp

                @if ($index === 1)
                    {{-- Center: what the game is doing right now. --}}
                    <div class="flex w-20 shrink-0 flex-col items-center gap-0.5 text-center">
                        @if ($this->isLive)
                            <span class="flex items-center gap-1 text-micro font-semibold text-red-600 dark:text-red-400">
                                <span class="size-1.5 animate-pulse rounded-full bg-current"></span>
                                {{ $game->status_detail ?? 'Live' }}
                            </span>

                            @if ($game->down_distance_text)
                                <span @class([
                                    'text-micro font-medium',
                                    'text-red-600 dark:text-red-400' => $game->is_red_zone,
                                    'text-zinc-500' => ! $game->is_red_zone,
                                ])>{{ $game->down_distance_text }}</span>
                            @endif
                        @elseif ($game->completed)
                            <span class="text-stat font-semibold">Final</span>
                        @else
                            <span class="text-micro font-medium text-zinc-500">
                                {{ $game->kickoff_at->setTimezone(config('cfb.timezone'))->format('D M j') }}
                            </span>
                            <span class="text-stat font-semibold">
                                {{ $game->kickoff_at->setTimezone(config('cfb.timezone'))->format('g:ia') }}
                            </span>
                        @endif
                    </div>
                @endif

                <div @class([
                    'flex min-w-0 flex-1 items-center gap-2',
                    'flex-row-reverse text-right' => $index === 1,
                ])>
                    {{-- The game page is WHERE the team links live — cards
                         send every tap here precisely because these exist. --}}
                    <a
                        @if ($side['team']) href="{{ route('team', $side['team']) }}" wire:navigate @endif
                        @class([
                            'flex min-w-0 items-center gap-2',
                            'flex-row-reverse' => $index === 1,
                        ])
                    >
                        {{-- Sized to the two-line identity beside it — the mark
                             is how a team is recognized before the letters are
                             read, and at size-6 it was subordinate to its own
                             abbreviation. --}}
                        <x-team-logo :team="$side['team']" size="lg" class="shrink-0" />

                        <div class="flex min-w-0 flex-col">
                            <span class="flex items-center gap-1 truncate text-sm font-semibold @if ($index === 1) justify-end @endif @if ($lost) text-zinc-400 @endif">
                                @if ($possession)
                                    <span class="size-1.5 shrink-0 rounded-full bg-amber-500" title="Possession"></span>
                                @endif
                                @if ($side['rank'])
                                    <span class="text-micro font-medium text-zinc-400">{{ $side['rank'] }}</span>
                                @endif
                                {{ $side['team']?->abbreviation ?? 'TBD' }}
                            </span>

                            <span class="truncate text-micro text-zinc-500">
                                @if ($this->isLive && $side['timeouts'] !== null)
                                    {{ str_repeat('●', $side['timeouts']) }}{{ str_repeat('○', max(0, 3 - $side['timeouts'])) }}
                                @else
                                    {{ $side['record'] }}
                                @endif
                            </span>
                        </div>
                    </a>

                    @if ($game->completed || $this->isLive)
                        <span @class([
                            'tabular shrink-0 text-2xl tracking-tight',
                            'font-bold' => ! $lost,
                            'font-semibold text-zinc-400' => $lost,
                        ])>{{ $side['score'] }}</span>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($this->isLive && $game->last_play_text)
            <p class="mt-2 truncate text-micro text-zinc-500">{{ $game->last_play_text }}</p>
        @endif
    </div>

    {{-- The line score: quarters plus total, the R/H/E analogue. Free — the
         scoreboard feed already carries it. --}}
    @if (($game->completed || $this->isLive) && $game->home_line_scores)
        <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800">
            <table class="w-full text-stat">
                <thead>
                    <tr class="border-b border-zinc-100 text-micro text-zinc-400 dark:border-zinc-800/60">
                        <th class="px-3 py-1 text-left font-medium"><span class="sr-only">Team</span></th>
                        @foreach ($game->home_line_scores as $quarter => $points)
                            <th class="w-8 px-1.5 py-1 text-center font-medium">{{ $quarter < 4 ? $quarter + 1 : 'OT'.($quarter > 4 ? $quarter - 3 : '') }}</th>
                        @endforeach
                        <th class="w-10 px-3 py-1 text-right font-semibold">T</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->sides as $side)
                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60">
                            <td class="px-3 py-1.5 font-medium">{{ $side['team']?->abbreviation ?? 'TBD' }}</td>
                            @foreach ($side['line'] ?? [] as $points)
                                <td class="tabular px-1.5 py-1.5 text-center text-zinc-500">{{ $points }}</td>
                            @endforeach
                            <td class="tabular px-3 py-1.5 text-right font-bold">{{ $side['score'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- One tab is not a tab set. A pregame screen has only its preview, so
         the strip is omitted rather than rendered as a lone unpressable pad. --}}
    @if (count($this->tabs) > 1)
        <div class="flex items-center justify-between gap-2">
            <x-gutter-tabs
                :items="$this->tabs"
                :selected="$tab"
                model="tab"
                label="Game sections"
                key-prefix="gametab"
            />
        </div>
    @endif

    @if ($tab === 'preview')
        @include('partials.game-preview')
    @elseif ($tab === 'live')
        @include('partials.game-live')
    @elseif ($tab === 'recap')
        @include('partials.game-recap')
    @elseif ($tab === 'box')
        @include('partials.game-box-score')
    @elseif ($tab === 'scoring')
        @include('partials.game-scoring')
    @elseif ($tab === 'drives')
        @include('partials.game-drives')
    @elseif ($tab === 'odds')
        @include('partials.game-odds')
    @endif

    {{-- When, where and how to watch — once, at the foot of every tab. It
         replaced a caption line under the tab strip, and sits here rather
         than back up there so it is always available without pushing a box
         score down the screen to reach it. --}}
    <x-game-info :game="$game" :attendance="$this->summary?->attendance ?? $game->attendance" />

    @include('partials.game-league-sheet')
</div>
