<?php

use App\Actions\ChangeGroupMode;
use App\Actions\HandOffCommissioner;
use App\Actions\JoinGroup;
use App\Actions\LeaveGroup;
use App\Actions\RecordUxEvent;
use App\Actions\RemoveGroupMember;
use App\Actions\SetGroupIcon;
use App\Enums\ContestMode;
use App\Enums\UxSignal;
use App\Exceptions\ContestFull;
use App\Exceptions\GroupNeedsCommissioner;
use App\Exceptions\ModeChangeBlocked;
use App\Exceptions\NotGroupCommissioner;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\WalletTooLight;
use App\Livewire\Concerns\MakesPicks;
use App\Livewire\Concerns\UploadsImages;
use App\Models\Contest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\User;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Services\Contests\SpreadGrader;
use App\Support\ImageUpload;
use App\Support\Cadence;
use App\Support\InviteTemplates;
use App\Support\Seats;
use App\Support\SlateFeasibility;
use App\Support\Voice;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * THE CLUBHOUSE — one group's home, rebuilt from the app's own DNA: the
 * hero band up top and a two-tab plate. SLATE is pure play — the shared
 * pick surface and nothing else talking over it. STANDINGS is everything
 * social: the viewer's own line, the invite panel, this week's table, the
 * season ledger, and the members. The 2026-08-30 pass merged the old
 * Season and Members tabs in here so the play tab answers only "make
 * your picks"; legacy ?view= values normalize across.
 *
 * Private groups are members-only; lobbies are readable by anyone signed
 * in, who sees the week's slate as a non-interactive preview behind the
 * join button. Every mutation rides an Action that enforces its own
 * authority — the @if around a button here is presentation, not the gate.
 *
 * Relations live in computeds, never on the model property: Livewire
 * re-hydrates `$group` WITHOUT relations on every request, so a template
 * reading `$group->contests` is a lazy-load 500 waiting for its second
 * request.
 */
new class extends Component
{
    use MakesPicks {
        refreshPicks as refreshPickState;
    }
    use UploadsImages;
    use WithFileUploads;

    /** The palette columns ride every card-feeding load — drop one and the cards silently un-brand. */
    private const TEAM_COLUMNS = 'id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark,color,alt_color,header_style';

    /**
     * ONE strip, four stops. The screen briefly had two — a plate of
     * Slate|Standings with a gutter of Standings|Members|Invite beneath
     * it — which put THREE rows of navigation over the content once the
     * area nav is counted, and repeated the word Standings on two of
     * them. A reader cannot tell which row owns which decision.
     *
     * So the plate is gone rather than the gutter: x-plate is documented
     * as two tabs and throws above three, and four stops is exactly the
     * case x-gutter-tabs exists for ("more tabs than two-or-three").
     *
     * `invite` normalizes away for a room — rooms are joined from the
     * lobby, never by invitation — so a room's strip is one stop shorter.
     *
     * `talk` (2026-09-01) is the thread's stop, members only: the hero's
     * Talk icon and the Standings-foot link-row both went, because a stop
     * that owns the door does not need two worse ones. A non-member's
     * `?view=talk` folds to the slate the way a room's `invite` folds. The
     * pick SURFACE stays chat-free — the thread mounts on its own tab and
     * nowhere near partials/pick-slate. Five stops fit at 390 only with the
     * gutter's `fill` variant (cells sized to their words); a sixth would
     * not fit at all, which is why the mode brief is an accordion.
     */
    private const VIEWS = ['slate', 'standings', 'members', 'invite', 'talk'];

    public Group $group;

    #[Url(except: 'slate')]
    public string $view = 'slate';

    /** The pivot modal's chosen target — a ContestMode backing value. */
    public ?string $pivotTo = null;

    /**
     * The commissioner's chosen icon file, held only between the file
     * dialog and the write. Named for the file rather than the column so
     * nothing reads it as the group's current mark.
     */
    public $iconFile = null;

    public function mount(Group $group): void
    {
        $this->group = $group;
        $this->view = $this->normalizedView($this->view);

        abort_unless($group->isLobby() || $this->seatOf(auth()->user()) !== null, 403);

        // Each kind lives at its own address: a shared link always reads
        // /contests/... for a room and /groups/... for a group. Keyed on
        // the WRONG address specifically, so a component mounted outside
        // any route (a test, an embed) just renders.
        if ($group->isRoom() && request()->routeIs('pickem.group')) {
            $this->redirectRoute('pickem.room', $group, navigate: true);
        } elseif (! $group->isRoom() && request()->routeIs('pickem.room')) {
            $this->redirectRoute('pickem.group', $group, navigate: true);
        }

        // THE STATE-AWARE FRONT DOOR: a bare visit opens to the tab with
        // the answers — Standings once the viewer's entry is in and the
        // card is playing, the pick surface any other time. An explicit
        // ?view= is somebody's stated intent and always wins.
        if (request()->query('view') === null && $this->opensToStandings()) {
            $this->view = 'standings';
        }

        $this->countSlateEntry();
    }

    /**
     * The funnel's "a member who could pick, and had not yet, opened this
     * slate" — the denominator that makes the first pick a rate rather than
     * a count.
     *
     * THE ENTRY GUARD IS WHAT MAKES IT A RATE. `first_pick_made` fires once
     * per (user, slate) FOR ALL TIME, on the entry row being new
     * (App\Actions\MakePick). Counting every open against that put a
     * per-day denominator over a per-lifetime numerator: a member who picked
     * on Tuesday and reopened their sheet on Wednesday and Thursday added
     * two more opens that no pick could ever answer, so the reported
     * pick-through FELL as engagement rose — 48% on the week this was
     * caught, a floor rather than a measurement. Skipping the member who
     * already has an entry puts both counters on one population, and the
     * difference between them is genuinely abandonment.
     *
     * RESIDUAL, on purpose: somebody who opens the slate on three days and
     * never picks still counts three times, so the rate remains a floor. The
     * exact figure would need a durable per-user marker, and this pipeline
     * is aggregate-only by rule — the once-a-day dedupe key is the only
     * place a user id appears at all, it is TTL'd in Redis and never
     * persisted. A truer number is not worth persisting who read what.
     *
     * The per-day `handleOnce` stays and keeps doing its original job: this
     * fires on MOUNT, and a `wire:navigate` hop re-mounts.
     *
     * Free: `$this->slate` is a memoized computed the slate view is about to
     * render anyway, the guard skips it entirely on the other tabs, and the
     * entry check is one `exists()` on a table this screen has already
     * scoped.
     */
    private function countSlateEntry(): void
    {
        if ($this->view !== 'slate' || auth()->guest() || $this->seatOf(auth()->user()) === null) {
            return;
        }

        if ($this->slate?->isPublished() !== true) {
            return;
        }

        $hasEntered = SlateEntry::query()
            ->where('slate_id', $this->slate->id)
            ->where('user_id', auth()->id())
            ->exists();

        if ($hasEntered) {
            return;
        }

        app(RecordUxEvent::class)->handleOnce(
            UxSignal::SlateEntered,
            "{$this->slate->id}:".auth()->id(),
        );
    }

    /** #[Url] hydrates without firing this hook, hence mount() normalizes too. */
    public function updatedView(string $value): void
    {
        $this->view = $this->normalizedView($value);
    }

    /**
     * The group's contest — the season-long game this room plays.
     *
     * One contest per group per season arrives with the next slice's
     * migration; until the dev-era duplicates collapse, FIELD() fronts the
     * main event deterministically rather than whichever row inserted
     * first.
     */
    #[Computed]
    public function contest(): ?Contest
    {
        return $this->group->contests()
            ->orderByRaw("FIELD(mode, 'tiered', 'classic', 'woodshed')")
            ->first();
    }

    /**
     * The pick'em week this group is currently playing, resolved once for
     * every computed that needs it — the slate, and the feasibility of
     * building one.
     */
    #[Computed]
    public function currentWeek(): ?Week
    {
        if ($this->contest === null) {
            return null;
        }

        $weekId = app(CfbCalendar::class)->defaultWeekId($this->contest->season_year);

        return $weekId === null ? null : Week::find($weekId);
    }

    /**
     * THE CARD BEING PLAYED, with everything the pick surface renders.
     *
     * Scoped onCard(), not to the week: an ESPN week can hold two
     * Saturdays, and this used to take whichever row the engine returned
     * first — which for a group carrying a Week 0 draft is the draft,
     * while My Picks read the published card. See Slate::scopeOnCard().
     */
    #[Computed]
    public function slate(): ?Slate
    {
        if ($this->contest === null || $this->currentWeek === null) {
            return null;
        }

        $team = self::TEAM_COLUMNS;

        return Slate::query()
            ->where('contest_id', $this->contest->id)
            ->onCard($this->currentWeek)
            ->with([
                "games.game.homeTeam:{$team}",
                "games.game.awayTeam:{$team}",
                "tiebreakerGame.game.homeTeam:{$team}",
                "tiebreakerGame.game.awayTeam:{$team}",
                'tiebreakerTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                'entries.user:id,first_name,last_name,handle',
                'contest.group:id,name,kind',
            ])
            ->first();
    }

    /**
     * Whether this Saturday can field this group's mode at all, and what
     * it would take — see App\Support\SlateFeasibility. Week 0 holds
     * eight usable games, which fills neither Shotgun's ten nor the
     * Woodshed's fifteen, and a "Build the slate" button over that is a
     * door into a wizard whose publish can only refuse.
     *
     * NULL is "cannot tell" — no contest, no week, no Saturday — and the
     * caller must not read it as "no": an unanswerable question leaves
     * the commissioner's door exactly where it was. Asked only inside the
     * branch that renders it, because it runs the builder's own candidate
     * pass.
     *
     * @return array{ok: bool, viable: int, needed: int, next: \Carbon\CarbonInterface}|null
     */
    #[Computed]
    public function slateWindow(): ?array
    {
        if ($this->contest === null) {
            return null;
        }

        $week = $this->currentWeek;
        $saturday = $week === null ? null : Cadence::activeSaturday($week);

        if ($week === null || $saturday === null) {
            return null;
        }

        return SlateFeasibility::for($this->contest, $week, $saturday);
    }

    /**
     * Where the week's surface stands, for the badge and the standings
     * table: null while nothing is published, then upcoming → live →
     * prelim → final. Matches the partial's own derivation.
     */
    #[Computed]
    public function surfaceStatus(): ?string
    {
        $slate = $this->slate;

        if ($slate === null || ! $slate->isPublished()) {
            return null;
        }

        return match (true) {
            $slate->status === Slate::SETTLED => 'final',
            $slate->status === Slate::PRELIM => 'prelim',
            $slate->games->contains(fn ($slateGame) => $slateGame->game->hasKickedOff()) => 'live',
            default => 'upcoming',
        };
    }

    /**
     * The week's room, ranked: live totals as one aggregate over picks
     * (the walletTotals() philosophy), the stamped final_points once the
     * week settles. On a Woodshed slate the Bear sits in the table too —
     * a label row ranked like anyone, never a winner (no entry to win
     * with), his running total computed from relations already in memory.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function weekStandings(): Collection
    {
        $slate = $this->slate;

        if ($slate === null || ! $slate->isPublished()) {
            return collect();
        }

        $totals = Pick::query()
            ->join('slate_games', 'slate_games.id', '=', 'picks.slate_game_id')
            ->where('slate_games.slate_id', $slate->id)
            ->groupBy('picks.user_id')
            ->selectRaw('picks.user_id, COALESCE(SUM(picks.points), 0) AS pts')
            ->pluck('pts', 'user_id')
            ->map(fn ($pts) => (int) $pts);

        $rows = $slate->entries
            ->map(fn (SlateEntry $entry) => [
                'user' => $entry->user,
                'team' => $this->memberTeams[$entry->user_id] ?? null,
                'label' => null,
                'key' => null,
                'icon' => null,
                'won' => $slate->status === Slate::SETTLED && (bool) $entry->won,
                'points' => $slate->status === Slate::SETTLED
                    ? ($entry->final_points ?? 0)
                    : ($totals[$entry->user_id] ?? 0),
            ]);

        $bearPoints = $this->bearPoints($slate);

        if ($bearPoints !== null) {
            $rows->push([
                'user' => null,
                'label' => 'The Bear',
                'key' => 'bear',
                'icon' => 'paw-print',
                'won' => false,
                'points' => $bearPoints,
            ]);
        }

        return $rows
            ->sortByDesc('points')
            ->values()
            ->map(fn (array $row, int $i) => [
                'rank' => $i + 1,
                'user' => $row['user'],
                'team' => $row['team'] ?? null,
                'label' => $row['label'],
                'key' => $row['key'],
                'icon' => $row['icon'],
                'won' => $row['won'],
                'cells' => [$row['points']],
            ]);
    }

    /**
     * The Bear's running total on a Woodshed slate — raw tier points over
     * his kicked-off games, the same frozen-line arithmetic as anyone's,
     * computed from the loaded slate with ZERO extra queries. Null when
     * this slate fields no Bear.
     */
    private function bearPoints(Slate $slate): ?int
    {
        if ($slate->bear_theme === null || $this->contest === null) {
            return null;
        }

        $engine = $this->contest->mode->engine($this->contest->settings);

        if (! $engine->hasBear()) {
            return null;
        }

        $grader = app(SpreadGrader::class);

        return (int) $slate->games
            ->filter(fn ($slateGame) => $slateGame->bear_team_id !== null && $slateGame->game->hasKickedOff())
            ->sum(fn ($slateGame) => $grader->resultFor($slateGame, $slateGame->game, $slateGame->bear_team_id) === Pick::WIN
                ? $engine->pointsFor($slateGame)
                : 0);
    }

    /**
     * The season ledger — weekly wins, then total points, with this
     * week's live number riding along. Every member appears: a zero week
     * count is an honest aggregate over no rows, not a substituted value.
     *
     * @return Collection<int, array{rank: int, user: User, won: bool, cells: list<int|string>}>
     */
    #[Computed]
    public function seasonStandings(): Collection
    {
        if ($this->contest === null) {
            return collect();
        }

        /*
         * THE SEASON LEDGER, and a practice week is not on it. An
         * exhibition slate grades, pays XP and crowns its own week's
         * winner — what it never does is move the season, which is the
         * whole reason a launch can rehearse in front of real people.
         * Slate::counts() says this in one place; a join cannot call it,
         * so the column is asked here by the same name.
         */
        $aggregates = SlateEntry::query()
            ->join('slates', 'slates.id', '=', 'slate_entries.slate_id')
            ->where('slates.contest_id', $this->contest->id)
            ->where('slates.status', Slate::SETTLED)
            ->where('slates.exhibition', false)
            ->groupBy('slate_entries.user_id')
            ->selectRaw('slate_entries.user_id, COALESCE(SUM(slate_entries.won), 0) AS wins, COALESCE(SUM(slate_entries.final_points), 0) AS pts')
            ->get()
            ->keyBy('user_id');

        // The Bear's label row has no user and no season ledger to join.
        $week = $this->weekStandings
            ->filter(fn (array $row) => $row['user'] !== null)
            ->keyBy(fn (array $row) => $row['user']->id);

        $priorRanks = $this->priorRanks();

        return $this->members
            ->map(fn (GroupMember $seat) => [
                'user' => $seat->user,
                'wins' => (int) ($aggregates[$seat->user_id]->wins ?? 0),
                'points' => (int) ($aggregates[$seat->user_id]->pts ?? 0),
                'week' => isset($week[$seat->user_id]) ? $week[$seat->user_id]['cells'][0] : null,
            ])
            ->sortBy([['wins', 'desc'], ['points', 'desc']])
            ->values()
            ->map(fn (array $row, int $i) => [
                'rank' => $i + 1,
                // The movement since the last settled Saturday — null until
                // two weeks exist to compare, and null renders nothing.
                'delta' => isset($priorRanks[$row['user']->id])
                    ? $priorRanks[$row['user']->id] - ($i + 1)
                    : null,
                'user' => $row['user'],
                'team' => $this->memberTeams[$row['user']->id] ?? null,
                'won' => false,
                'cells' => [$row['wins'], $row['points'], $row['week'] ?? '—'],
            ]);
    }

    /**
     * THE MOVEMENT BASELINE: everyone's rank on the ledger as it stood
     * BEFORE the latest settled Saturday, so Monday's table can say who
     * climbed. Empty until two countable weeks exist — one week has no
     * "before" worth inventing.
     *
     * @return array<int, int> user id => prior rank
     */
    private function priorRanks(): array
    {
        $settled = $this->contest->slates()
            ->where('status', Slate::SETTLED)
            ->where('exhibition', false)
            ->orderByDesc('saturday')
            ->limit(2)
            ->pluck('id');

        if ($settled->count() < 2) {
            return [];
        }

        $prior = SlateEntry::query()
            ->join('slates', 'slates.id', '=', 'slate_entries.slate_id')
            ->where('slates.contest_id', $this->contest->id)
            ->where('slates.status', Slate::SETTLED)
            ->where('slates.exhibition', false)
            ->where('slates.id', '!=', $settled->first())
            ->groupBy('slate_entries.user_id')
            ->selectRaw('slate_entries.user_id, COALESCE(SUM(slate_entries.won), 0) AS wins, COALESCE(SUM(slate_entries.final_points), 0) AS pts')
            ->get()
            ->keyBy('user_id');

        return $this->members
            ->map(fn (GroupMember $seat) => [
                'user_id' => $seat->user_id,
                'wins' => (int) ($prior[$seat->user_id]->wins ?? 0),
                'points' => (int) ($prior[$seat->user_id]->pts ?? 0),
            ])
            ->sortBy([['wins', 'desc'], ['points', 'desc']])
            ->values()
            ->mapWithKeys(fn (array $row, int $i) => [$row['user_id'] => $i + 1])
            ->all();
    }

    /**
     * Each member's FIRST followed team — the identity chip beside the
     * handle in the standings, the pilot's rivalries made visible. One
     * query across every member; a member with no follows has no chip,
     * and nothing is substituted for it.
     *
     * @return array<int, \App\Models\Team>
     */
    #[Computed]
    public function memberTeams(): array
    {
        return \App\Models\Team::query()
            ->join('team_follows', 'team_follows.team_id', '=', 'teams.id')
            ->where('team_follows.position', 1)
            ->whereIn('team_follows.user_id', $this->members->pluck('user_id'))
            ->get([
                'teams.id', 'teams.slug', 'teams.location', 'teams.display_name',
                'teams.short_display_name', 'teams.logo', 'teams.logo_dark',
                'team_follows.user_id as follower_id',
            ])
            ->keyBy('follower_id')
            ->all();
    }

    /**
     * EVERYBODY'S CALLS, revealed per game — the accountability grid.
     * Rows are the week's ranked entrants (the viewer hoisted first, the
     * Bear riding along on a Woodshed slate); columns are the slate's
     * games; a cell stays a dash until THAT game kicks off, then shows
     * the picked side, then wears its grade. Our locks are per kickoff,
     * so the reveal is per game — nobody's late-window pick leaks while
     * the noon games are already talking.
     *
     * ONE pick-level read for the whole room, asked only when the
     * Standings tab renders and only once the card is playing; null any
     * other time, and null renders nothing.
     *
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}|null
     */
    #[Computed]
    public function picksGrid(): ?array
    {
        $slate = $this->slate;

        if ($slate === null || ! in_array($this->surfaceStatus, ['live', 'prelim', 'final'], true)) {
            return null;
        }

        $games = $slate->games;

        $picks = Pick::query()
            ->whereIn('slate_game_id', $games->pluck('id'))
            ->get()
            ->groupBy('user_id');

        $columns = $games->map(fn ($slateGame) => [
            'key' => $slateGame->id,
            'away' => $slateGame->game->awayTeam->abbreviation,
            'home' => $slateGame->game->homeTeam->abbreviation,
        ])->values()->all();

        $abbreviate = fn ($slateGame, ?int $teamId): ?string => match ($teamId) {
            $slateGame->game->home_team_id => $slateGame->game->homeTeam->abbreviation,
            $slateGame->game->away_team_id => $slateGame->game->awayTeam->abbreviation,
            default => null,
        };

        $grader = app(SpreadGrader::class);
        $engine = $this->contest?->mode->engine($this->contest->settings);
        $bear = $slate->bear_theme !== null && ($engine?->hasBear() ?? false);

        $rows = $this->weekStandings
            ->map(function (array $standing) use ($games, $picks, $abbreviate, $grader, $bear, $slate) {
                $isBear = $standing['key'] === 'bear';
                $mine = $standing['user'] === null
                    ? collect()
                    : ($picks->get($standing['user']->id) ?? collect())->keyBy('slate_game_id');

                $cells = $games->map(function ($slateGame) use ($mine, $abbreviate, $grader, $isBear, $bear) {
                    // The reveal rule: THIS game's kickoff, nothing else's.
                    if (! $slateGame->game->hasKickedOff()) {
                        return ['state' => 'hidden', 'abbr' => null, 'tone' => 'neutral'];
                    }

                    if ($isBear) {
                        if (! $bear || $slateGame->bear_team_id === null) {
                            return ['state' => 'none', 'abbr' => null, 'tone' => 'neutral'];
                        }

                        $tone = $slateGame->game->completed
                            ? ($grader->resultFor($slateGame, $slateGame->game, $slateGame->bear_team_id) === Pick::WIN ? 'win' : 'loss')
                            : 'neutral';

                        return ['state' => 'pick', 'abbr' => $abbreviate($slateGame, $slateGame->bear_team_id), 'tone' => $tone];
                    }

                    $pick = $mine->get($slateGame->id);

                    if ($pick === null) {
                        // An absent pick on a kicked game is an honest zero.
                        return ['state' => 'none', 'abbr' => null, 'tone' => 'neutral'];
                    }

                    return [
                        'state' => 'pick',
                        'abbr' => $abbreviate($slateGame, $pick->picked_team_id),
                        'tone' => $pick->result === null ? 'neutral' : ($pick->result === Pick::WIN ? 'win' : 'loss'),
                    ];
                })->values()->all();

                return [
                    'name' => $standing['user'] !== null
                        ? ($standing['user']->handle !== null ? '@'.$standing['user']->handle : $standing['user']->name)
                        : ($standing['label'] ?? '—'),
                    'viewer' => $standing['user']?->id === auth()->id(),
                    'icon' => $standing['icon'],
                    'points' => $standing['cells'][0],
                    'cells' => $cells,
                ];
            })
            // The viewer's own line first — the row you scan for is the
            // row you never have to hunt.
            ->sortBy(fn (array $row) => $row['viewer'] ? 0 : 1)
            ->values()
            ->all();

        return $rows === [] ? null : ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * The modes this group could pivot TO — every live mode but the one
     * it plays. The Woodshed's arrival grew the old single-answer seam
     * into this choice, so the modal is a radiogroup now.
     *
     * @return Collection<int, ContestMode>
     */
    #[Computed]
    public function pivotChoices(): Collection
    {
        if ($this->contest === null) {
            return collect();
        }

        return collect(ContestMode::cases())
            ->filter(fn (ContestMode $mode) => $mode->available() && $mode !== $this->contest->mode)
            ->values();
    }

    #[Computed]
    public function seasonHasHistory(): bool
    {
        // Countable history, matching the table it gates: a season of
        // nothing but practice weeks has an empty ledger, and the empty
        // state says so rather than printing a table of zeroes.
        return $this->contest !== null
            && $this->contest->slates()
                ->where('status', Slate::SETTLED)
                ->where('exhibition', false)
                ->exists();
    }

    #[Computed]
    public function members()
    {
        return $this->group->memberships()
            ->with('user:id,first_name,last_name,handle')
            ->orderByRaw("role = 'commissioner' desc")
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The link this group travels by — the primary invite, crediting the
     * sharer when they hold a handle. Never rendered for lobbies: rooms
     * are joined from the lobby, not by invitation.
     */
    #[Computed]
    public function joinUrl(): string
    {
        return route('pickem.join', array_filter([
            'code' => $this->group->code,
            'by' => auth()->user()?->handle,
        ]));
    }

    /**
     * The ready-to-send messages for this group's invite.
     *
     * Sized from the CONTEST, never the mode's default — a downsized
     * Shotgun room deals eight games and an invitation promising ten is
     * the group lying about the game it is selling.
     *
     * An empty list when there is no contest yet: nothing to describe is
     * not the same as a generic description, and the panel renders the
     * link and the QR without this block rather than inventing rules.
     *
     * @return list<array{key: string, label: string, hint: string, subject: string|null, body: string}>
     */
    #[Computed]
    public function inviteTemplates(): array
    {
        $contest = $this->contest;

        if ($contest === null) {
            return [];
        }

        return InviteTemplates::for(
            $this->group,
            $contest->mode,
            $this->joinUrl,
            $contest->mode->engine($contest->settings)->slateSize(),
        );
    }

    #[Computed]
    public function isCommissioner(): bool
    {
        return $this->seatOf(auth()->user())?->isCommissioner() ?? false;
    }

    #[Computed]
    public function isMember(): bool
    {
        return $this->seatOf(auth()->user()) !== null;
    }

    /**
     * The room's week — resolved by id, never off the model property:
     * Livewire re-hydrates `$group` without relations, so `$group->week`
     * in the template is a lazy-load 500 on the second request.
     */
    #[Computed]
    public function roomWeek(): ?\App\Models\Week
    {
        return $this->group->week_id === null ? null : \App\Models\Week::find($this->group->week_id);
    }

    /**
     * EVERY SEAT THE READER HOLDS, for the switcher in the hero — the
     * same read My Picks stands on, so the menu here and the sections
     * there can never list a different set of groups. Lazy past the
     * groups: the week only resolves for the contests heading, the room
     * Saturdays only when a room is held.
     */
    #[Computed]
    public function seats(): Seats
    {
        return Seats::for(auth()->user());
    }

    /**
     * The strip's stops, both kinds: the pick surface, everything social,
     * and — for a member — the thread. A room's Standings simply skips the
     * season ledger it does not have.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function tabs(): array
    {
        $tabs = [
            'slate' => 'Slate',
            'standings' => 'Standings',
            'members' => 'Members',
        ];

        // Rooms never advertise invites, so the stop does not exist for
        // them — matching normalizedView(), which sends the address back.
        if (! $this->group->isLobby()) {
            $tabs['invite'] = 'Invite';
        }

        // The thread belongs to the people in it: last stop, members only,
        // matching normalizedView(), which folds an outsider's address.
        if ($this->isMember) {
            $tabs['talk'] = 'Talk';
        }

        return $tabs;
    }

    /**
     * Whether a table here may print real names.
     *
     * A private group is people who know each other — a handle is a
     * worse answer than a name for somebody you invited by text. A
     * PUBLIC room is strangers, and putting their legal names on a
     * screen anybody can walk into is a different thing entirely, so
     * the seam is the kind of room, not a preference.
     */
    #[Computed]
    public function showsRealNames(): bool
    {
        return ! $this->group->isLobby();
    }

    /**
     * The viewer's own line above the tables — rank and points where the
     * room has them, an em dash where it does not: null means no data,
     * and a seat with no entry has no rank worth inventing. Null when the
     * viewer holds no seat at all (an outsider previewing a lobby).
     *
     * @return array{name: string, stats: list<array{label: string, value: string}>}|null
     */
    #[Computed]
    public function youStrip(): ?array
    {
        $user = auth()->user();

        if ($user === null || $this->seatOf($user) === null) {
            return null;
        }

        $playing = in_array($this->surfaceStatus, ['live', 'prelim', 'final'], true);
        $weekRow = $playing
            ? $this->weekStandings->first(fn (array $row) => $row['user']?->id === $user->id)
            : null;

        $stats = [
            ['label' => 'Wk rank', 'value' => $weekRow === null ? '—' : '#'.$weekRow['rank']],
            ['label' => 'Wk pts', 'value' => $weekRow === null ? '—' : (string) $weekRow['cells'][0]],
        ];

        if (! $this->group->isRoom()) {
            $seasonRow = $this->seasonHasHistory
                ? $this->seasonStandings->first(fn (array $row) => $row['user']->id === $user->id)
                : null;

            $stats[] = ['label' => 'Wins', 'value' => $seasonRow === null ? '—' : (string) $seasonRow['cells'][0]];
            $stats[] = ['label' => 'Pts', 'value' => $seasonRow === null ? '—' : (string) $seasonRow['cells'][1]];
        }

        return [
            'name' => $user->handle !== null ? '@'.$user->handle : $user->name,
            'stats' => $stats,
        ];
    }

    public function join(JoinGroup $action): void
    {
        try {
            $action->handle(auth()->user(), $this->group);
        } catch (PickemParticipationGated) {
            $this->addError('group', Voice::line('groups.verify_first'));

            return;
        } catch (ContestFull) {
            $this->addError('group', Voice::line('contest.room.full'));

            return;
        } catch (WalletTooLight) {
            $this->addError('group', Voice::line('contest.room.too_light'));

            return;
        }

        session()->flash('status', Voice::line('groups.joined', ['group' => $this->group->name]));
        // The strip too: a seat is what puts the Talk stop on it.
        unset($this->members, $this->isMember, $this->isCommissioner, $this->tabs);
    }

    public function leave(LeaveGroup $action)
    {
        try {
            $action->handle(auth()->user(), $this->group);
        } catch (GroupNeedsCommissioner) {
            $this->addError('group', Voice::line('groups.leave.commissioner'));

            return;
        }

        session()->flash('status', Voice::line('groups.left', ['group' => $this->group->name]));

        return $this->redirectRoute('pickem.home', navigate: true);
    }

    /** The modal's radio tap. Validation is presentation; the Action gates. */
    public function choosePivot(string $mode): void
    {
        $choice = ContestMode::tryFrom($mode);

        if ($choice !== null && $this->pivotChoices->contains($choice)) {
            $this->pivotTo = $choice->value;
        }
    }

    public function changeMode(ChangeGroupMode $action): void
    {
        $target = ContestMode::tryFrom($this->pivotTo ?? '');

        if ($target === null) {
            $this->addError('mode', Voice::line('mode.change.pick_one'));

            return;
        }

        try {
            $action->handle(auth()->user(), $this->group, $target);
        } catch (NotGroupCommissioner) {
            abort(403);
        } catch (ModeChangeBlocked $blocked) {
            $this->addError('mode', Voice::line($blocked->reason === ModeChangeBlocked::USED
                ? 'mode.change.blocked.used'
                : 'mode.change.blocked.inflight'));

            return;
        }

        Flux::modal('change-mode')->close();
        session()->flash('status', Voice::line('mode.change.done', ['mode' => $target->label()]));
        $this->pivotTo = null;

        unset(
            $this->contest, $this->pivotChoices, $this->slate, $this->surfaceStatus,
            $this->weekStandings, $this->seasonStandings, $this->seasonHasHistory,
            $this->youStrip, $this->picksGrid,
        );
    }

    /**
     * Stored the moment a file is chosen rather than behind a save button:
     * an icon is not a field anyone wants to review before committing, and
     * seeing it land on the band IS the confirmation. Same discipline as
     * the account photo, and the same cap — there is no resizing pipeline
     * in this app, so the size limit is the whole defense.
     */
    public function updatedIconFile(SetGroupIcon $action): void
    {
        $this->validate([
            'iconFile' => ImageUpload::rules(),
        ], [
            'iconFile.mimes' => ImageUpload::mimeMessage(),
            'iconFile.max' => ImageUpload::oversizedMessage(),
            'iconFile.dimensions' => 'That image is too small to read at icon size.',
        ]);

        try {
            $action->handle(auth()->user(), $this->group, $this->iconFile);
        } catch (NotGroupCommissioner) {
            abort(403);
        } catch (\Throwable $e) {
            // The disk refused (R2 answered NotImplemented for months, and
            // `throw => true` made that a 500 on this update). Report it,
            // say so in the icon's own error line, and leave the group on
            // whatever mark it had — never a half-written path.
            report($e);
            $this->iconFile = null;
            $this->addError('iconFile', Voice::line('groups.icon.failed'));

            return;
        }

        $this->iconFile = null;
    }

    /**
     * Back to initials. The @if around the control is presentation; the
     * Action is the gate, as everywhere else on this screen.
     */
    public function removeIcon(SetGroupIcon $action): void
    {
        try {
            $action->clear(auth()->user(), $this->group);
        } catch (NotGroupCommissioner) {
            abort(403);
        }
    }

    public function remove(int $userId, RemoveGroupMember $action): void
    {
        $member = User::findOrFail($userId);

        try {
            $action->handle(auth()->user(), $this->group, $member);
        } catch (NotGroupCommissioner) {
            abort(403);
        }

        session()->flash('status', Voice::line('groups.member.removed', ['name' => $member->first_name]));
        unset($this->members);
    }

    /**
     * Hand the commissioner's seat to another member.
     *
     * The Action gates every rule; this only translates its refusals. A
     * 403 for the authorization miss (a non-commissioner reaching the
     * method) matches remove(); the argument refusals cannot be provoked
     * from the rendered screen — the button only exists on a seated
     * member's row — so they abort rather than growing user copy for a
     * state a reader cannot reach.
     *
     * isCommissioner is memoized and now WRONG for both people, so it is
     * dropped alongside members: the row this ran from has to re-render
     * with the badge and the buttons swapped.
     */
    public function handOff(int $userId, HandOffCommissioner $action): void
    {
        $member = User::findOrFail($userId);

        try {
            $action->handle(auth()->user(), $this->group, $member);
        } catch (NotGroupCommissioner) {
            abort(403);
        } catch (InvalidArgumentException) {
            abort(422);
        }

        session()->flash('status', Voice::line('groups.handoff.done', [
            'name' => $member->first_name,
            'group' => $this->group->name,
        ]));

        unset($this->members, $this->isCommissioner);
    }

    /** @return Collection<int, Slate> */
    protected function pickableSlates(): Collection
    {
        return collect([$this->slate])->filter(fn (?Slate $slate) => $slate?->isPublished());
    }

    /** A pick can create the week's entry, so the room state rides along. */
    protected function refreshPicks(): void
    {
        $this->refreshPickState();
        unset($this->slate, $this->surfaceStatus, $this->weekStandings, $this->seasonStandings, $this->youStrip, $this->picksGrid);
    }

    private function normalizedView(string $view): string
    {
        // The three-tab era's addresses keep landing. `members` is a real
        // stop again since 2026-09-01, so only `season` still folds.
        if ($view === 'season') {
            return 'standings';
        }

        // A room has no invite stop, so an address asking for one lands
        // on the standings rather than an empty screen — the same law
        // the panel itself keeps, kept here so the strip and the content
        // cannot disagree about which stops exist.
        if ($view === 'invite' && $this->group->isLobby()) {
            return 'standings';
        }

        // The thread is members-only, and the tab is absent for anyone
        // else — so the address folds to the pick surface, the same law.
        if ($view === 'talk' && ! $this->isMember) {
            return 'slate';
        }

        return in_array($view, self::VIEWS, true) ? $view : 'slate';
    }

    /**
     * A bare visit's tab: Standings once the viewer's entry is in and the
     * card is playing (live through final), the pick surface otherwise.
     * Explicit ?view= never reaches this — mount() checks the querystring
     * first — and completing an entry mid-session never yanks the surface
     * away, because the answer is asked once, at the front door.
     */
    private function opensToStandings(): bool
    {
        if (! in_array($this->surfaceStatus, ['live', 'prelim', 'final'], true)) {
            return false;
        }

        return $this->seatOf(auth()->user()) !== null
            && $this->slate !== null
            && $this->entryComplete($this->slate);
    }

    private function seatOf(?User $user): ?GroupMember
    {
        return $user === null
            ? null
            : $this->group->memberships()->where('user_id', $user->id)->first();
    }
}; ?>

<div class="flex flex-col gap-5">
    @php
        $heroMeta = $group->isRoom()
            ? collect([
                $this->contest?->mode->label(),
                $this->roomWeek ? \App\Support\Cadence::displayWeekLabel($this->roomWeek, $this->slate?->saturday) : null,
                $group->member_cap !== null
                    ? $this->members->count().' of '.$group->member_cap.' seats'
                    : $this->members->count().' '.Str::plural('member', $this->members->count()),
            ])->filter()->implode(' · ')
            : null;
    @endphp

    <x-group-hero :group="$group" :contest="$this->contest" :members-count="$this->members->count()" :meta="$heroMeta">
        {{-- THE NAME IS THE SWITCHER. The same control that sits above
             My Picks' fork, worn here as the title: one tap to any other
             seat without going back out through the overview. The sr-only
             h1 keeps the heading for assistive tech — the house shows no
             visible h1 off Scores, and the visible name is a control now. --}}
        <x-slot:title>
            <h1 class="sr-only">{{ $group->name }}</h1>
            <x-group-switcher :seats="$this->seats" :current="$group" variant="hero" class="min-w-0" />
        </x-slot:title>

        {{-- THE MARK IS THE CONTROL, the same gesture the account photo
             teaches: tapping the thing you want to change beats a second
             button on a row that is already tight at 390px. The label
             wraps a hidden input so it stays keyboard-reachable and
             screen-reader-named, which a click handler on a div is not.

             Commissioners of PRIVATE groups only. A public room is
             house-run with no commissioner seat, so this never renders
             there — and the Action would refuse it anyway. --}}
        @if ($this->isCommissioner && ! $group->isLobby())
            <x-slot:icon>
                <label class="group relative shrink-0 cursor-pointer" title="Change the group icon">
                    <x-group-icon :group="$group" class="size-9 text-micro sm:size-11 sm:text-sm" />

                    {{-- Only on hover and focus, so the mark is not
                         permanently wearing a badge. --}}
                    <span
                        class="absolute inset-0 flex items-center justify-center rounded-xl bg-zinc-900/60 text-white opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100"
                        aria-hidden="true"
                    >
                        <flux:icon name="camera" variant="micro" />
                    </span>

                    <x-image-file-input property="iconFile" label="Upload a group icon" />
                </label>
            </x-slot:icon>
        @endif

        {{-- ONE control on the band: the commissioner's cog. The Talk
             icon left this row on 2026-09-01 for a gutter tab of its own,
             the way the invite button left for the Invite stop — a stop
             that owns the door does not need a worse one beside the name,
             and the title row gets ~44px back at 390. The band renders NO
             wrapper for a member, whose slot is empty. --}}
        <x-slot:actions>
            @if ($this->isCommissioner && $this->pivotChoices->isNotEmpty())
                <flux:modal.trigger name="change-mode">
                    <button
                        type="button"
                        class="rounded-lg bg-zinc-100 p-2 text-zinc-700 transition-colors hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700"
                        aria-label="Change the group's game"
                    >
                        <flux:icon name="cog-6-tooth" variant="mini" />
                    </button>
                </flux:modal.trigger>
            @endif
        </x-slot:actions>
    </x-group-hero>

    {{-- A fresh seat's one remaining step. A private group seats an
         unverified account (JoinGroup), so this is where the nudge has to
         live: picks, the Lock and the tiebreaker stay behind the gate, and
         the row says so until the address is confirmed. Its own component,
         so its poll re-renders the row alone. --}}
    <livewire:verify-callout :body-key="'verify.picks.body'" :dismissable="false" @email-verified="$refresh" />

    {{-- BELOW the band, never inside it: the validation messages are a
         sentence long and the hero has no width for them at 390px.
         Without this a rejected image looks like nothing happened at
         all. Removal lives here too — only once there is something to
         remove, so the line is absent for every group on the initials
         fallback. --}}
    @if ($this->isCommissioner && ! $group->isLobby())
        <div class="flex items-center gap-3">
            <flux:error name="iconFile" />

            <flux:icon.loading wire:loading wire:target="iconFile" class="size-4 shrink-0 text-zinc-400" />

            @if ($group->icon !== null)
                <button
                    type="button"
                    wire:click="removeIcon"
                    class="ms-auto shrink-0 text-sm font-medium text-zinc-500 hover:underline"
                >
                    Remove icon
                </button>
            @endif
        </div>
    @endif

    {{-- The pivot's announcement, lingering a week so members who missed
         the note still walk in on the news rather than a changed room. --}}
    @if ($this->contest?->mode_changed_at?->gt(now()->subDays(7)))
        <flux:callout icon="megaphone">
            <flux:callout.heading>New mode: {{ $this->contest->mode->label() }}</flux:callout.heading>
            <flux:callout.text>{{ Voice::line('group.mode_changed', ['mode' => $this->contest->mode->label()]) }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- A room's week has a winner, and the room says so out loud —
         above the fork, so both tabs walk in on the news. --}}
    @if ($group->isRoom() && $this->surfaceStatus === 'final')
        @php
            $winners = $this->weekStandings
                ->filter(fn ($row) => $row['won'])
                ->map(fn ($row) => $row['user']->handle !== null ? '@'.$row['user']->handle : $row['user']->name);
        @endphp

        @if ($winners->isNotEmpty())
            <flux:callout icon="trophy">
                <flux:callout.heading>{{ Voice::line('contest.room.winner', ['name' => $winners->implode(' & ')]) }}</flux:callout.heading>
            </flux:callout>
        @endif
    @endif

    @if (session('status'))
        <x-notice tone="success">{{ session('status') }}</x-notice>
    @endif

    {{-- The pick notice renders INSIDE the pick surface, beside the tap
         that produced it — a refusal parked up here was off-screen from
         the card it was answering, dressed in a green success box. --}}

    @error('group')
        <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror

    {{-- Only a lobby is readable from outside, so this door is theirs.
         The notice renders here too: a member the commissioner removes
         mid-session loses the pick surface — and with it the surface's
         own notice slot — on the very render that answers their tap. --}}
    @if (! $this->isMember)
        @if ($this->notice)
            <x-notice :tone="$this->noticeTone">{{ $this->notice }}</x-notice>
        @endif

        <div class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:subheading class="min-w-0">{{ Voice::line('groups.lobbies.subheading') }}</flux:subheading>
            <flux:button wire:click="join" wire:loading.attr="disabled" wire:target="join" variant="primary" class="shrink-0">Join this lobby</flux:button>
        </div>
    @endif

    {{-- THE ONE STRIP. Four stops in a group, three in a room. It is a
         gutter rather than a plate because x-plate is documented as two
         tabs and throws above three — and because the screen briefly
         carried BOTH, which stacked three rows of navigation over the
         content and said "Standings" on two of them. --}}
    <x-gutter-tabs
        :items="$this->tabs"
        :selected="$view"
        model="view"
        variant="fill"
        label="Group sections"
        key-prefix="group-tab"
    />

    <div
        wire:loading.class="opacity-60 pointer-events-none"
        wire:target="view"
        class="flex flex-col gap-5 motion-safe:transition-opacity"
    >
    @if ($view === 'slate')
        {{-- WHAT THIS GROUP PLAYS — the mode brief, collapsed. It sat under
             the hero as a blurb and a frame line (a room: the flavor's
             pitch and its zinger), with the full rules card at the foot of
             Standings: the same facts, two places, and 60px between the
             band and the strip that a returning member never reads. One
             accordion now, at the top of the pick surface, ungated on
             membership and OUTSIDE the published fork — a group with no
             slate yet still says what it is, and the frame line must be
             in the DOM whatever the card is doing. The identity row
             carries the pitch clamped to two lines (a room's flavor pitch
             is a sentence; the shelf's one-line truncation is the thing
             LobbyFlavorTest exists to catch), the payload the rule lines,
             the frame or the zinger, and the laws every mode shares. --}}
        @if ($this->contest !== null)
            @php
                $roomFlavor = $group->isRoom() ? $group->flavorEnum() : null;
                // The card THIS contest deals, not the mode's default one:
                // Shotgun's size is frozen per Saturday, so a Week 0 room
                // of eight games must not be pitched as ten.
                $briefGames = $this->contest->mode->engine($this->contest->settings)->slateSize();
                $roomZinger = $roomFlavor === null
                    ? ''
                    : Voice::line($roomFlavor->zingerKey(), ['conference' => $roomFlavor->conferenceName() ?? '']);
            @endphp

            <x-mode-rules
                :mode="$this->contest->mode"
                :games="$briefGames"
                :pitch="$roomFlavor?->blurb($briefGames)"
                clamp
            >
                @if (! $group->isLobby())
                    <p class="text-micro text-zinc-400 dark:text-zinc-500">{{ Voice::line('group.private.frame') }}</p>
                @elseif ($roomZinger !== '')
                    <p class="text-micro italic text-zinc-400 dark:text-zinc-500">&ldquo;{{ $roomZinger }}&rdquo;</p>
                @endif

                @include('partials.pickem-laws')
            </x-mode-rules>
        @endif

        @if ($this->slate?->isPublished())
            {{-- THE SIDECAR, and the guard is the whole design. Before
                 kickoff there is no table to show — everybody is on zero and
                 nobody's picks are revealed — so the column is not opened at
                 all rather than reserved and left blank, which is the bug
                 App\Support\Rail's docblock exists to name. From the first
                 snap it opens and the running week rides beside the cards
                 instead of behind a tab.

                 `surfaceStatus` is tested FIRST on purpose: it is already
                 computed for this tab, and PHP short-circuits, so a slate
                 that has not kicked off never touches `weekStandings` and
                 the tab costs exactly what it costs today. Once it has, the
                 computed is one aggregate over picks already being read.

                 Additive: this is the Standings tab's own "This week" table,
                 the same component with the same rows, so a phone reader
                 reaches every figure in it one tap away. --}}
            @php
                $slateSidecar = in_array($this->surfaceStatus, ['live', 'prelim', 'final'], true)
                    && $this->weekStandings->isNotEmpty();
            @endphp

            {{-- PURE PLAY: the standings live on the Standings tab now, so
                 the first pickable card is the first thing this tab says. --}}
            <div @class([
                'flex flex-col gap-5',
                'lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start lg:gap-6' => $slateSidecar,
            ])>
                <div class="flex min-w-0 flex-col gap-5">
                    @include('partials.pick-slate', [
                        'slate' => $this->slate,
                        'interactive' => $this->isMember,
                        'sidecar' => $slateSidecar,
                    ])
                </div>

                @if ($slateSidecar)
                    {{-- `hidden … lg:flex`, the app rail's own string, and NOT
                         the game screen's "foot of the page on a phone" trade.
                         The difference is that this table is not tail content
                         borrowed from the bottom of this tab — it already has
                         a home one tap away on Standings, and adding a second
                         copy underneath the cards would be a phone change on a
                         screen this pass is not allowed to touch. Additive is
                         satisfied by the tab, not by a duplicate. --}}
                    <div class="hidden flex-col gap-4 lg:flex">
                        <x-standings-table
                            :rows="$this->weekStandings"
                            :status="$this->surfaceStatus"
                            :headings="['Pts']"
                            title="This week"
                        />
                    </div>
                @endif
            </div>
        @else
            {{-- Dashed border = "not yet", the house grammar for a promise. --}}
            <div class="flex flex-col gap-2 rounded-xl border border-dashed border-zinc-300 px-4 py-4 dark:border-zinc-700">
                @if ($this->isCommissioner && $this->contest !== null)
                    @php $window = $this->slateWindow; @endphp

                    {{-- A SATURDAY THAT CANNOT SEAT THE MODE. The lobby
                         dashes a room it cannot spawn; a group already
                         exists, so its clubhouse says the same thing in
                         its own words and takes the door away rather than
                         opening a wizard whose publish can only refuse.
                         Null means the question could not be asked — the
                         door stays exactly where it was. --}}
                    @if ($window !== null && ! $window['ok'])
                        <p class="text-sm font-medium">Not enough games this Saturday.</p>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $this->contest->mode->label() }} needs {{ $window['needed'] }} games and this Saturday's card has {{ $window['viable'] }}.
                            The next slate can go up Saturday, {{ $window['next']->format('M j') }}.
                        </p>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('group.slate.thin') }}</p>
                    @else
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('group.slate.build_prompt') }}</p>
                        <flux:button
                            :href="route('pickem.build', $group)"
                            wire:navigate
                            size="sm"
                            variant="primary"
                            class="self-start"
                        >
                            Build the slate
                        </flux:button>
                    @endif
                @else
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('group.slate.waiting') }}</p>
                @endif
            </div>
        @endif
    @elseif ($view === 'standings')
        {{-- Polls only while a slate game is live, reading only our own
             database — the Saturday heartbeat, not a feed. --}}
        <div
            @if ($this->surfaceStatus === 'live') wire:poll.30s.visible @endif
            class="flex flex-col gap-5"
        >
            @if ($this->youStrip !== null)
                <x-you-strip :name="$this->youStrip['name']" :stats="$this->youStrip['stats']" />
            @endif

            @if (in_array($this->surfaceStatus, ['live', 'prelim', 'final'], true) && $this->weekStandings->isNotEmpty())
                <x-standings-table
                    :rows="$this->weekStandings"
                    :status="$this->surfaceStatus"
                    :headings="['Pts']"
                    title="This week"
                    :names="$this->showsRealNames"
                />
            @endif

            {{-- The accountability grid — everybody's calls, revealed
                 per game, once the card is playing. --}}
            @if ($this->picksGrid !== null)
                <x-picks-grid :grid="$this->picksGrid" />
            @endif

            {{-- The season ledger — groups and evergreen tables; a
                 one-Saturday room has no season to stand on. --}}
            @if (! $group->isRoom())
                @if ($this->seasonHasHistory || $this->surfaceStatus !== null)
                    <x-standings-table
                        :rows="$this->seasonStandings"
                        :headings="['Wins', 'Pts', 'This week']"
                        title="Season"
                        :status="$this->surfaceStatus === 'live' ? 'live' : null"
                        :names="$this->showsRealNames"
                    />
                @else
                    <p class="rounded-xl border border-dashed border-zinc-300 px-4 py-3 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        {{ Voice::line('group.season.empty') }}
                    </p>
                @endif
            @endif

        </div>
    @elseif ($view === 'members')
        {{-- THE ROSTER, and the management that goes with it. This was a
             collapsed disclosure at the bottom of the standings stack,
             which put the one control that transfers a league behind a
             chevron nobody opened. --}}
        <div class="flex flex-col gap-5">
                <div class="flex flex-col gap-2">
                    @foreach ($this->members as $seat)
                        <div
                            wire:key="member-{{ $seat->id }}"
                            class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 px-4 py-2.5 dark:border-zinc-700"
                        >
                            {{-- min-w-0, or the nowrap identity keeps its
                                 whole min-content width and shoves the
                                 buttons off the row at 390. --}}
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium">
                                    @if ($this->showsRealNames)
                                        {{ $seat->user->name }}
                                        @if ($seat->user->handle)
                                            <span class="text-sm text-zinc-500 dark:text-zinc-400">&commat;{{ $seat->user->handle }}</span>
                                        @endif
                                    @else
                                        {{-- A public room is strangers: the
                                             handle is the identity, and the
                                             name is nobody's business. --}}
                                        {{ $seat->user->handle ? '@'.$seat->user->handle : $seat->user->name }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if ($seat->isCommissioner())
                                    <flux:badge size="sm" color="amber">Commissioner</flux:badge>
                                @elseif ($this->isCommissioner)
                                    {{-- The handoff. Confirmed because it is
                                         not undoable BY THE PERSON DOING IT:
                                         once the seat moves, only the new
                                         commissioner can move it back. --}}
                                    <flux:button
                                        wire:click="handOff({{ $seat->user_id }})"
                                        wire:confirm="{{ Voice::line('groups.handoff.confirm', ['name' => $seat->user->first_name, 'group' => $group->name]) }}"
                                        size="sm"
                                        variant="ghost"
                                    >
                                        Make commissioner
                                    </flux:button>
                                    <flux:button
                                        wire:click="remove({{ $seat->user_id }})"
                                        wire:confirm="Remove {{ $seat->user->first_name }} from the group?"
                                        size="sm"
                                        variant="ghost"
                                    >
                                        Remove
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    @if ($this->isMember)
                        <flux:button
                            wire:click="leave"
                            wire:confirm="Leave {{ $group->name }}?"
                            variant="ghost"
                            class="self-start text-red-600 dark:text-red-400"
                        >
                            Leave group
                        </flux:button>
                    @endif
                </div>
        </div>
    @elseif ($view === 'invite')
        {{-- THE INVITE, a stop of its own. It carries a link, a code, a QR
             and three ready-to-send messages, which is more than a
             disclosure on top of the standings could hold without burying
             what people came for. Rooms never reach here —
             normalizedView() sends the address back. --}}
        <div class="flex flex-col gap-5">
            @if ($this->isMember && ! $group->isLobby())
                <x-invite-panel
                    :url="$this->joinUrl"
                    :code="$group->code"
                    :title="$group->name"
                    :share-text="Voice::line('groups.invite.share_text', ['group' => $group->name])"
                    :hint="Voice::line('groups.invite.hint', ['group' => $group->name])"
                    :open="true"
                    :templates="$this->inviteTemplates"
                />
            @endif
        </div>
    @elseif ($view === 'talk')
        {{-- THE THREAD, on its own stop. Not lazy: the tab tap IS the
             intersection, and the exclusive branch mounts fresh per entry.
             Members only — the strip has no stop for anyone else and
             normalizedView() folds their address, so this @if is the
             belt to those braces. The pick surface above never mounts
             one; the conversation renders its own subheading line. --}}
        @if ($this->isMember)
            <livewire:conversation :topic="$group" :key="'talk-group-'.$group->id" />
        @endif
    @endif

    </div>

    {{-- THE PIVOT: one deliberate act per season, consequences said
         plainly, and the announcement is a statement — never a checkbox.
         Three live modes made this a radiogroup: pick the new mode, then
         throw the one lever. --}}
    @if ($this->isCommissioner && $this->pivotChoices->isNotEmpty() && $this->contest !== null)
        <flux:modal name="change-mode" class="w-full max-w-md">
            <div class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">Change the game</flux:heading>
                    <flux:subheading>{{ Voice::line('mode.change.warning') }}</flux:subheading>
                </div>

                <div role="radiogroup" aria-label="New mode" class="flex flex-col gap-2">
                    @foreach ($this->pivotChoices as $choice)
                        <x-mode-card
                            wire:key="pivot-{{ $choice->value }}"
                            wire:click="choosePivot('{{ $choice->value }}')"
                            :mode="$choice"
                            :selected="$pivotTo === $choice->value"
                        />
                    @endforeach
                </div>

                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('mode.change.note') }}</p>

                @error('mode')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                <div class="flex items-center gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Keep {{ $this->contest->mode->label() }}</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="changeMode" variant="primary" :disabled="$pivotTo === null">
                        {{ $pivotTo !== null ? 'Switch to '.ContestMode::from($pivotTo)->label() : 'Switch' }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
