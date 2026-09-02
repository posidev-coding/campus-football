<?php

use App\Actions\EnterPicks;
use App\Actions\JoinGroup;
use App\Enums\ContestMode;
use App\Exceptions\ContestFull;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\WalletTooLight;
use App\Models\Contest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Services\Contests\SuggestSlate;
use App\Support\Cadence;
use App\Support\Lobby;
use App\Support\SlateFeasibility;
use App\Support\RankLadder;
use App\Support\Seats;
use App\Support\Voice;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * MY PICKS — the reader's own pick'em week, and nothing anybody else's.
 * One column ordered by urgency: the week's dateline, the slates still
 * waiting on picks, the groups they play in, last week's payoff, their
 * rung on the ladder, and the two doors out (an invite code, and the
 * Lobby). Outside the `pickem` flag it keeps the coming-soon promise the
 * Picks tab shipped with, verbatim, at this address too.
 *
 * Split from the lobby 2026-08-20: one screen was doing two jobs — your
 * week and the store — and thirteen open rooms is the volume that made
 * the seam obvious. THE STORE IS NOT HERE. What is here is one COUNT of
 * what is open, sold as a door, because the alternative is a dashboard
 * paying for the whole inventory graph to print a number.
 *
 * One query per CONCERN across all groups, never per card: contests,
 * slates, my picks, my entries, my weekly wins — each is a single read
 * however many groups the reader belongs to. The zones above are pure
 * PROJECTIONS of that one cards() read, never a second query.
 */
new class extends Component
{
    public string $code = '';

    /**
     * THIS WEEK or RESULTS — a genuine fork in the screen rather than a
     * filter: the week is what you can still act on, and results are what
     * already happened. They shared one scroll until Last week and the
     * ladder sat below four zones of live cards, where a Monday payoff is
     * something you have to go looking for.
     */
    #[Url(except: 'week')]
    public string $view = 'week';

    public const VIEWS = ['week', 'results'];

    /**
     * Replay flag from Account's "Replay the Picks tour" — a URL param, so
     * the button is a plain link and a replay is shareable in a bug report.
     * Home's `?tour=1` grammar, deliberately: two walks with two different
     * replay verbs would be two things to remember.
     */
    #[Url(as: 'tour', except: false)]
    public bool $tourReplay = false;

    public function mount(): void
    {
        $this->view = $this->normalizedView($this->view);

        /*
         * WHERE THE ECONOMY STARTS. Arriving here stamps the first visit and
         * restocks the cooler for the football week — lazily, so no schedule
         * writes a row for somebody who never came back, and keyed, so this
         * pays once however many times the screen is opened.
         *
         * mount(), never render(): a Livewire re-render is cheap and often,
         * and while the key stops it paying twice nothing would stop it
         * asking twice. Behind the same gate as the rest of the personal
         * screen — outside the flag this address keeps its coming-soon
         * promise, and a currency should not be paid out under it.
         */
        if ($this->showPersonal) {
            app(EnterPicks::class)->handle(auth()->user());
        }
    }

    /** #[Url] hydrates without firing this hook, hence mount() normalizes too. */
    public function updatedView(string $value): void
    {
        $this->view = $this->normalizedView($value);
    }

    private function normalizedView(string $view): string
    {
        return in_array($view, self::VIEWS, true) ? $view : 'week';
    }

    #[Computed]
    public function showPersonal(): bool
    {
        return auth()->check() && Feature::active('pickem');
    }

    /**
     * THE PICKS WALK. Its own gate beside Home's, reading its OWN column:
     * `picks_tour_completed_at`, never `picks_first_seen_at`. The first-visit
     * stamp is the economy's — it is what pays the weekly top-off — and
     * folding the two together would mean a replay from Account re-triggered
     * a grant, or a reader who waved the coach marks away looked to the
     * economy like somebody who had never arrived.
     *
     * Behind `showPersonal` as well as its own flag: outside the pick'em
     * flag this screen is a coming-soon promise, and walking somebody
     * through an economy that is not open yet is a tour of nothing.
     */
    #[Computed]
    public function showTour(): bool
    {
        $user = auth()->user();

        if ($user === null || ! $this->showPersonal || ! Feature::active('picks-tour')) {
            return false;
        }

        return $this->tourReplay || ! $user->hasTouredPicks();
    }

    /**
     * The walk finished or was skipped. Clearing the replay flag is the
     * load-bearing half — Home's lesson: `showTour` short-circuits on it, so
     * a replayed walk would stay "showing" after its own last card, and
     * dropping it strips `?tour=1` so a reload does not restart a walk the
     * reader just closed.
     */
    #[On('tour-finished')]
    public function tourFinished(): void
    {
        $this->tourReplay = false;

        unset($this->showTour);
    }

    /**
     * The reader's rung, and the climb to the next one.
     *
     * The header chip has room for the NAME and nothing else, so this is the
     * only surface where the next rung is named — which is what stops
     * "Captain" from being a word with no scale behind it. No extra query:
     * walletTotals() is memoized per request and the ladder is arithmetic.
     *
     * @return array{name: string, floor: int, next: string|null, at: int|null, remaining: int|null, progress: float}|null
     */
    #[Computed]
    public function rank(): ?array
    {
        return auth()->check()
            ? RankLadder::for($this->walletXp)
            : null;
    }

    #[Computed]
    public function walletXp(): int
    {
        return auth()->check() ? auth()->user()->walletTotals()['xp'] : 0;
    }

    /**
     * THE READER'S OWN LINE — the standings' you-strip, second render
     * site. Below `sm` the app header does not render at all, so a phone
     * reader saw no rung, no XP and no credits on either pick'em door:
     * the gamification the screen is built around was invisible exactly
     * where the screen starts.
     *
     * ZERO new queries. `rank`/`walletXp` are already read for the
     * ladder, credits ride the same memoized walletTotals() SUM, and wins
     * is a projection of cards(). Values are PRE-RENDERED with an em dash
     * where there is no data — the component never substitutes one.
     *
     * @return array{name: string, stats: list<array{label: string, value: string}>}|null
     */
    #[Computed]
    public function youStrip(): ?array
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $wins = $this->cards->sum('wins');

        return [
            // The clubhouse strip's own rule: the handle when it is
            // claimed, the name until then.
            'name' => $user->handle !== null ? '@'.$user->handle : $user->name,
            'stats' => [
                ['label' => 'Rank', 'value' => $this->rank['name'] ?? '—'],
                ['label' => 'XP', 'value' => number_format($this->walletXp)],
                ['label' => 'Tallboys', 'value' => number_format($user->walletTotals()['credits'])],
                /*
                 * A DASH until the first win exists. "0 Wins" every
                 * Sunday in September is a counter with no decision
                 * attached to it, and a zero somebody has not earned yet
                 * reads as a verdict on them.
                 */
                ['label' => 'Wins', 'value' => $wins > 0 ? (string) $wins : '—'],
            ],
        ];
    }

    /**
     * EVERY SEAT THE READER HOLDS, read once. The group switcher above the
     * fork and the card query below both stand on this one read, so the
     * menu and the overview can never list a different set of groups.
     */
    #[Computed]
    public function seats(): Seats
    {
        return Seats::for(auth()->user());
    }

    /**
     * Every group card's state, assembled flat.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function cards()
    {
        $groups = $this->seats->groups;

        if ($groups->isEmpty()) {
            return collect();
        }

        $contests = Contest::query()
            ->whereIn('group_id', $groups->pluck('id'))
            ->where('season_year', app(CfbCalendar::class)->currentYear())
            ->get()
            ->keyBy('group_id');

        /*
         * The week is resolved BEFORE the slates, because the card being
         * played is a SATURDAY and an ESPN week can hold two of them.
         * Keyed on the week alone, keyBy() silently kept whichever row
         * came last — the published card here, while the clubhouse's
         * ->first() took the other one. Two screens, one week, two
         * answers. See Slate::scopeOnCard().
         *
         * Read off the Seats read the switcher shares, and STILL gated on
         * the contests: with none there is no card to sell, and a week
         * resolved anyway would hand ribbonClock() a deadline for a group
         * that has no contest to build one for.
         */
        $week = $contests->isEmpty() ? null : $this->seats->week();
        $weekId = $week?->id;

        $slates = $week === null ? collect() : Slate::query()
            ->whereIn('contest_id', $contests->pluck('id'))
            ->onCard($week)
            ->with('games.game:id,kickoff_at,status,completed')
            ->get()
            ->keyBy('contest_id');

        // My tallies, one aggregate each: picks made + live points per
        // slate, my entry per slate, my weekly wins per contest.
        $made = Pick::query()
            ->join('slate_games', 'slate_games.id', '=', 'picks.slate_game_id')
            ->whereIn('slate_games.slate_id', $slates->pluck('id'))
            ->where('picks.user_id', auth()->id())
            ->groupBy('slate_games.slate_id')
            ->selectRaw('slate_games.slate_id, COUNT(*) AS made, COALESCE(SUM(picks.points), 0) AS pts')
            ->get()
            ->keyBy('slate_id');

        $entries = SlateEntry::query()
            ->whereIn('slate_id', $slates->pluck('id'))
            ->where('user_id', auth()->id())
            ->get()
            ->keyBy('slate_id');

        // Season wins, and a practice week never earned one — the same
        // ledger rule the clubhouse's season table reads.
        $wins = SlateEntry::query()
            ->join('slates', 'slates.id', '=', 'slate_entries.slate_id')
            ->whereIn('slates.contest_id', $contests->pluck('id'))
            ->where('slates.status', Slate::SETTLED)
            ->where('slates.exhibition', false)
            ->where('slate_entries.user_id', auth()->id())
            ->groupBy('slates.contest_id')
            ->selectRaw('slates.contest_id, COALESCE(SUM(slate_entries.won), 0) AS wins')
            ->pluck('wins', 'contest_id');

        /*
         * The deadline belongs to a SATURDAY, not to a week — and a card
         * without a slate yet is asking about the Saturday being sold, not
         * the week's busiest one. Passing the Week here resolved through
         * saturdayOf(), so inside the split opening week (8/29 and 9/5) every
         * group on 8/29 was shown a deadline a week late. The settle sweep
         * already reads `$slate->saturday` and the publish sweep already
         * reads activeSaturday(); this is the third caller agreeing with them.
         */
        $pending = $week === null ? null : $this->seats->saturday();
        $fallbackDeadline = $pending === null ? null : Cadence::slateDeadline($pending);

        /*
         * The Saturday's usable pool, resolved AT MOST ONCE for the whole
         * screen and only if a commissioner card actually asks: this is
         * the builder's own candidate pass, and per card it would be a
         * slate suggestion per row. Private groups carry no themed
         * filter, so one count answers for all of them. By reference —
         * an arrow function would capture the null by value forever.
         */
        $viable = null;

        return $groups->map(function (Group $group) use ($contests, $slates, $made, $entries, $wins, $fallbackDeadline, $week, $pending, &$viable) {
            $contest = $contests->get($group->id);
            $slate = $contest === null ? null : $slates->get($contest->id);
            $tally = $slate === null ? null : $made->get($slate->id);
            $entry = $slate === null ? null : $entries->get($slate->id);

            $state = match (true) {
                $slate === null || $slate->status === Slate::DRAFT => 'waiting',
                $slate->status === Slate::SETTLED => 'final',
                $slate->status === Slate::PRELIM => 'prelim',
                $slate->games->contains(fn ($slateGame) => $slateGame->game->hasKickedOff()) => 'live',
                default => 'upcoming',
            };

            $commissioner = $group->pivot->role === GroupMember::COMMISSIONER;

            /*
             * Can this week even be built? Only a commissioner staring at
             * a slateless group is asking, so nothing else pays for the
             * answer — and a week we cannot ask about (no week, no
             * Saturday) leaves the door alone rather than closing it.
             */
            $buildable = true;

            if ($commissioner && $state === 'waiting' && $contest !== null && ! $group->isRoom() && $week !== null && $pending !== null) {
                $viable ??= app(SuggestSlate::class)->viableCount($contest, $week, $pending);

                $buildable = SlateFeasibility::fromCount($viable, $contest, $pending)['ok'];
            }

            return [
                'group' => $group,
                'contest' => $contest,
                'commissioner' => $commissioner,
                'buildable' => $buildable,
                'state' => $state,
                'made' => (int) ($tally->made ?? 0),
                'total' => $slate?->games->count() ?? 0,
                'points' => $state === 'final'
                    ? (int) ($entry->final_points ?? 0)
                    : (int) ($tally->pts ?? 0),
                // The ENTRY, not just the picks: every game called and the
                // week's question answered. Derived here from operands
                // already in scope, the same rule the pick surface states
                // in MakesPicks::entryComplete() — there is no stored flag
                // to disagree with the picks it describes.
                'entryIn' => $slate !== null
                    && $slate->status === Slate::PUBLISHED
                    && $slate->games->count() > 0
                    && (int) ($tally->made ?? 0) >= $slate->games->count()
                    && ($slate->tiebreaker_slate_game_id === null || $entry?->tiebreaker_total !== null),
                'won' => (bool) ($entry->won ?? false),
                'wins' => (int) ($wins[$contest?->id] ?? 0),
                'firstKick' => $slate?->firstKickoff(),
                // A ROOM WHOSE SATURDAY HAS BEEN PLAYED — the rule lives
                // on Seats now, beside the switcher that has to agree
                // with it: read off the room's OWN Saturday, never the
                // week id alone (.ai/rules/components-support.md).
                'past' => $this->seats->isPast($group),
                // A published slate answers for its OWN Saturday; a group
                // still waiting on one is told about the card being sold.
                'deadline' => $slate === null
                    ? $fallbackDeadline
                    : Cadence::slateDeadline($slate->saturday),
            ];
        });
    }

    /**
     * THE PRIVATE HALF — season-long groups the reader belongs to. A
     * pure projection of cards(), like every zone on this screen.
     *
     * Its own heading again since 2026-09-01: "My Groups", mirrored by
     * the switcher's menu. The 08-31 merge put the kind on every card
     * instead, and a stack of cards each carrying its own kind line read
     * as one product with fine print — the two headings are the same two
     * sections the menu shows, so the taxonomy is one thing said in two
     * places.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function groupCards()
    {
        return $this->cards->reject(fn (array $card) => $card['group']->isLobby())->values();
    }

    /**
     * THE PUBLIC HALF — the one-Saturday rooms being played RIGHT NOW,
     * and only those.
     *
     * A public room is a transient contest: it is joined for one
     * Saturday, it dies with that Saturday, and the next week is a fresh
     * decision. Carrying a played room into the new week put three dead
     * seats above the reader's real groups and told them they were
     * already in this Saturday's public contests — which is the exact
     * opposite of the product. The rooms that played are not deleted
     * (their leaderboards and their URLs outlive them); they leave THIS
     * screen for {@see pastRooms()}, which points at History.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function roomCards()
    {
        return $this->cards
            ->filter(fn (array $card) => $card['group']->isRoom() && ! $card['past'])
            ->values();
    }

    /**
     * THE SEATS THAT ARE OVER — rooms whose Saturday has been played.
     *
     * Never rendered as cards. They are a COUNT and a door to History,
     * because the reader still needs a way back to a room they played
     * and My Picks is not that way: this screen is the week in front of
     * them.
     *
     * A projection of cards(), like everything else here.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function pastRooms()
    {
        return $this->cards
            ->filter(fn (array $card) => $card['group']->isRoom() && $card['past'])
            ->values();
    }

    /**
     * The always-open house tables: kind = lobby with NO week. Neither
     * of the zones above may absorb them — an evergreen table is not a
     * private group and it is not a room that plays one Saturday, and
     * filing it under either heading is a label the data does not
     * support. Render-guarded, so it is invisible until one exists.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function tableCards()
    {
        return $this->cards
            ->filter(fn (array $card) => $card['group']->isLobby() && ! $card['group']->isRoom())
            ->values();
    }

    /**
     * The zone that answers "what do I do right now": published slates
     * still taking picks where mine are not all in. A pure projection of
     * cards() — no query of its own.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function needsPicks()
    {
        return $this->cards
            ->filter(fn (array $card) => in_array($card['state'], ['upcoming', 'live'], true)
                && $card['total'] > 0
                && $card['made'] < $card['total'])
            ->values();
    }

    /**
     * EVERYTHING IS IN. Not "nothing to do" — the zone that asks for
     * picks simply vanished when the last entry landed, and a reader
     * who finished on Wednesday came back Saturday to a screen with no
     * word about it either way. Silence is the one answer a pick'em
     * screen cannot give about your picks.
     *
     * All three conditions, deliberately: seats held, nothing left to
     * ask, and at least one entry actually IN. Without the last one a
     * reader with only settled weeks — or only rooms whose Saturday is
     * gone — is told they are all in on nothing.
     *
     * A pure projection of cards(). No query, at any depth.
     */
    #[Computed]
    public function allIn(): bool
    {
        return $this->cards->isNotEmpty()
            && $this->needsPicks->isEmpty()
            && $this->cards->contains(fn (array $card) => $card['entryIn']);
    }

    /**
     * THE ONE THAT WANTS YOU NOW. A single hero rather than a stack of
     * them: four cards all shouting is four cards nobody reads, and the
     * question a reader actually has on Saturday morning is which one is
     * about to lock.
     *
     * A projection of needsPicks(), which is a projection of cards() —
     * no query of its own, at any depth.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function heroCard(): ?array
    {
        return $this->byUrgency()->first();
    }

    /**
     * HOW MANY MORE still want picks below the hero — a fact for one
     * plain line, never a second stack of rows. Every card that needed
     * picks used to render TWICE on this screen: as a compact row up
     * here and again as its own card in the sections below, which is
     * how a reader in four groups met eight cards before the fold. The
     * hero keeps the zone's one button; the cards below keep their own
     * state (the count, "Entry in", "Tiebreaker left").
     */
    #[Computed]
    public function needsMore(): int
    {
        return max(0, $this->needsPicks->count() - 1);
    }

    /**
     * needsPicks() by URGENCY: a slate already under way outranks one
     * that has not kicked, then soonest kickoff wins.
     *
     * A card with no kickoff to read sorts LAST rather than first — an
     * absent timestamp is missing data, never "kicks at the epoch", and
     * that is the direction a null sorts if you let it through a
     * comparison unread.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function byUrgency()
    {
        return $this->needsPicks->sort(function (array $a, array $b) {
            $liveA = $a['state'] === 'live' ? 0 : 1;
            $liveB = $b['state'] === 'live' ? 0 : 1;

            if ($liveA !== $liveB) {
                return $liveA <=> $liveB;
            }

            $kickA = $a['firstKick']?->getTimestamp();
            $kickB = $b['firstKick']?->getTimestamp();

            if ($kickA === null || $kickB === null) {
                return $kickA === $kickB ? 0 : ($kickA === null ? 1 : -1);
            }

            return $kickA <=> $kickB;
        })->values();
    }

    /**
     * Is there a fork to draw at all? A genuinely new reader has no cards
     * and no settled week, so both tabs would say the same nothing —
     * they keep the one scroll they have always had, and the plate
     * appears with their first seat.
     */
    #[Computed]
    public function hasTabs(): bool
    {
        return $this->cards->isNotEmpty() || $this->lastWeek->isNotEmpty();
    }

    /**
     * The tab in force. With no plate on the screen a bookmarked
     * ?view=results has no control to undo it, so it normalizes here,
     * silently, rather than hiding the first run behind an empty tab.
     */
    #[Computed]
    public function activeView(): string
    {
        return $this->hasTabs ? $this->view : 'week';
    }

    /**
     * The week's dateline entry from the calendar. Null skips the ribbon
     * entirely — never a substituted week.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function weekEntry(): ?array
    {
        return app(CfbCalendar::class)->defaultWeekEntry(app(CfbCalendar::class)->currentYear());
    }

    /**
     * The ribbon's one clock line, by urgency: games live now beats the
     * next kickoff beats a commissioner's slate deadline. Null when none
     * of it applies — the ribbon then carries the dateline alone.
     *
     * @return array{type: string, at: \Carbon\CarbonInterface|null}|null
     */
    #[Computed]
    public function ribbonClock(): ?array
    {
        $cards = $this->cards;

        if ($cards->contains(fn (array $card) => $card['state'] === 'live')) {
            return ['type' => 'live', 'at' => null];
        }

        $kick = $cards
            ->filter(fn (array $card) => $card['state'] === 'upcoming')
            ->pluck('firstKick')
            ->filter()
            ->min();

        if ($kick !== null) {
            return ['type' => 'kick', 'at' => $kick];
        }

        $waiting = $cards->first(fn (array $card) => $card['state'] === 'waiting'
            && $card['commissioner']
            && $card['deadline'] !== null);

        return $waiting === null ? null : ['type' => 'deadline', 'at' => $waiting['deadline']];
    }

    /**
     * The Monday payoff: my settled weeks from the last seven days.
     *
     * @return \Illuminate\Support\Collection<int, SlateEntry>
     */
    #[Computed]
    public function lastWeek()
    {
        return SlateEntry::query()
            ->where('user_id', auth()->id())
            ->whereHas('slate', fn ($q) => $q
                ->where('status', Slate::SETTLED)
                ->where('settled_at', '>=', now()->subDays(7)))
            ->with('slate.contest.group:id,name,kind,week_id')
            ->get();
    }

    /**
     * MY PLACE IN EACH OF LAST WEEK'S FIELDS — "3rd of 9" — from ONE
     * query across every slate on the tab, never one per row. History's
     * own pattern, because two screens printing the same sentence must
     * compute it the same way.
     *
     * Read ONLY from the Results branch. Computeds are lazy, so the week
     * tab never pays for it — and that laziness is pinned by a query
     * count, because a stray reference from the week's template would be
     * invisible here and cost every reader a read on every load.
     *
     * @return array<int, array{place: int, of: int}> keyed by slate id
     */
    #[Computed]
    public function places(): array
    {
        $slateIds = $this->lastWeek->pluck('slate_id');

        if ($slateIds->isEmpty()) {
            return [];
        }

        return SlateEntry::query()
            ->whereIn('slate_id', $slateIds)
            ->get(['slate_id', 'user_id', 'final_points'])
            ->groupBy('slate_id')
            ->map(function (Collection $field) {
                $ranked = $field->sortByDesc(fn (SlateEntry $entry) => $entry->final_points ?? 0)->values();
                $mine = $ranked->search(fn (SlateEntry $entry) => $entry->user_id === auth()->id());

                return [
                    'place' => $mine === false ? $ranked->count() : $mine + 1,
                    'of' => $ranked->count(),
                ];
            })
            ->all();
    }

    /**
     * THE WEEKS YOU WON, inside the payoff window. A projection of
     * lastWeek() — zero new queries — and NULL when there are none,
     * because the banner is a celebration and there is nothing to
     * celebrate quietly.
     *
     * @return \Illuminate\Support\Collection<int, SlateEntry>|null
     */
    #[Computed]
    public function payoff(): ?Collection
    {
        $won = $this->lastWeek->filter(fn (SlateEntry $entry) => $entry->won)->values();

        return $won->isEmpty() ? null : $won;
    }

    /**
     * Is this the FIRST time this session has seen these wins?
     *
     * The pick surface's celebration is fired by the act that earns it,
     * so a protected property surviving one response is enough. A payoff
     * banner ARRIVES instead — the reader did nothing to summon it — so
     * the entrance has to be spent against the wins themselves, in the
     * session, or every navigation back to Results replays a party for a
     * week they already know they won.
     *
     * Touched ONLY from the banner's own markup: nothing is marked seen
     * until the banner has actually rendered.
     */
    #[Computed]
    public function payoffFresh(): bool
    {
        if ($this->payoff === null) {
            // No wins, nothing fresh — and nothing to write down either.
            return false;
        }

        $ids = $this->payoff->pluck('id')->all();
        $seen = session('picks.payoff.seen', []);
        $fresh = array_diff($ids, $seen) !== [];

        if ($fresh) {
            session(['picks.payoff.seen' => array_values(array_unique([...$seen, ...$ids]))]);
        }

        return $fresh;
    }

    /**
     * HOW MANY rooms are open this Saturday — the teaser's whole payload.
     *
     * A COUNT, never the inventory: the browser owns the graph, and a
     * dashboard that reads openRooms() to print an integer is a second
     * full lobby read on every load. LobbyRoomsTest pins this number
     * equal to what the Lobby actually lists.
     */
    #[Computed]
    public function roomsOpen(): int
    {
        return $this->seats->openCount();
    }

    public function join(JoinGroup $action)
    {
        $code = strtoupper(trim($this->code));

        $group = $code === '' ? null : Group::query()->where('code', $code)->first();

        if ($group === null) {
            $this->addError('code', Voice::line('groups.join.bad_code'));

            return;
        }

        return $this->takeSeat($action, $group, 'code');
    }

    private function takeSeat(JoinGroup $action, Group $group, string $errorBag)
    {
        try {
            $action->handle(auth()->user(), $group);
        } catch (PickemParticipationGated) {
            $this->addError($errorBag, Voice::line('groups.verify_first'));

            return;
        } catch (ContestFull) {
            $this->addError($errorBag, Voice::line('contest.room.full'));

            return;
        } catch (WalletTooLight) {
            $this->addError($errorBag, Voice::line('contest.room.too_light'));

            return;
        }

        session()->flash('status', Voice::line('groups.joined', ['group' => $group->name]));

        // Each kind lands at its own address — no clubhouse double-hop.
        return $this->redirectRoute(
            $group->isRoom() ? 'pickem.room' : 'pickem.group',
            $group,
            navigate: true,
        );
    }
}; ?>

<div class="flex flex-col gap-6">
    @if ($this->showPersonal)
        {{-- ============================== MY PICKS =================== --}}
        {{-- The section strip names this place — the h1 stays for screen
             readers only, the house rule. --}}
        <h1 class="sr-only">My Picks</h1>

        <livewire:verify-callout :body-key="'verify.picks.body'" :dismissable="false" @email-verified="$refresh" />

        @if (session('status'))
            <x-notice tone="success">{{ session('status') }}</x-notice>
        @endif

        {{-- THE SWITCHER IS THE SCREEN'S NAME: which of your seats you are
             looking at, and one tap to any other. Pure navigation off the
             one Seats read — no Livewire state, no query of its own — and
             the one row that sits ABOVE the fork, because "where am I"
             comes before "which half". The hero variant since pass 2:
             title weight, start-aligned, the same first row the clubhouse
             opens with, so the two screens read as one system. Guarded on
             SEATS rather than on the fork, so the first run stays
             byte-identical. Not sticky: the z-ladder in views.md, and the
             tour overlay under the page root. --}}
        @if ($this->seats->hasSeats())
            <x-group-switcher :seats="$this->seats" variant="hero" />
        @endif

        {{-- THE FORK. Two areas, so a plate and not a gutter: what you can
             still act on, and what already happened. Above it sits only
             chrome that belongs to the whole screen — the callout and the
             flash both answer for either tab. --}}
        @if ($this->hasTabs)
            <x-plate
                :tabs="['week' => 'This week', 'results' => 'Results']"
                :selected="$this->activeView"
                model="view"
                key-prefix="picks-view"
            />
        @endif

        <div
            wire:loading.class="opacity-60 pointer-events-none"
            wire:target="view"
            class="flex flex-col gap-6 motion-safe:transition-opacity lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start lg:gap-6"
        >
        {{-- MAIN COLUMN: the urgency spine, in one column and in order. --}}
        <div class="flex min-w-0 flex-col gap-6">
        @if ($this->activeView === 'week')
        {{-- The week's dateline. No calendar entry, no ribbon — never a
             substituted week. --}}
        @if ($this->weekEntry !== null)
            <x-week-ribbon data-tour="week" :entry="$this->weekEntry" :clock="$this->ribbonClock" />
        @endif

        {{-- YOU, before anything on the screen asks you for something.
             Below `sm` there is no app header, so this is the only place
             a phone reader meets their own rung, XP and credits on the
             screen the whole ladder is played on.

             Guarded on the FORK, not on the wallet: a first run has no
             seat and no settled week, and the pitch it gets instead is
             byte-identical to the one it has always had. --}}
        @if ($this->hasTabs && $this->youStrip !== null)
            <x-you-strip data-you-strip data-tour="balance" :name="$this->youStrip['name']" :stats="$this->youStrip['stats']" />
        @endif

        {{-- What needs you right now: slates still taking your picks,
             each row walking straight into its clubhouse. --}}
        @if ($this->needsPicks->isNotEmpty())
            <div class="flex flex-col gap-2">
                <flux:subheading class="font-semibold text-zinc-900 dark:text-zinc-100">Needs your picks</flux:subheading>
                <flux:subheading>{{ Voice::line('lobby.needs.subheading') }}</flux:subheading>

                {{-- ONE HERO, and a count. The card closest to locking
                     wears the mode's own tile and carries the only button
                     on the zone; four heroes would be four cards nobody
                     reads, and the compact rows that used to follow were
                     every one of those cards drawn a second time. --}}
                @php
                    $hero = $this->heroCard;
                    $heroGroup = $hero['group'];
                    $heroMode = $hero['contest']?->mode ?? ContestMode::Classic;
                    $heroPalette = $heroMode->palette();
                    $heroZinger = Voice::line('picks.hero.zinger');
                    $heroHref = $heroGroup->isRoom()
                        ? route('pickem.room', $heroGroup)
                        : route('pickem.group', $heroGroup);
                @endphp

                <div
                    wire:key="hero-{{ $heroGroup->id }}"
                    class="flex flex-col gap-3 rounded-xl border p-4 {{ $heroPalette['tile'] }}"
                >
                    <div class="flex items-center gap-3">
                        {{-- The mark rides a neutral puck, the way every
                             mode tile in the house carries it. --}}
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-white/60 shadow-sm dark:bg-white/10">
                            <flux:icon :name="$heroMode->icon()" variant="mini" class="{{ $heroPalette['icon'] }}" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-bold leading-tight">{{ $heroGroup->name }}</span>
                            {{-- The Woodshed's tile is black in both
                                 schemes, and the count is the number a
                                 picker acts on — the mode says which
                                 weight reads on it rather than the screen
                                 sniffing it out of a class string. --}}
                            <x-slate-progress
                                :made="$hero['made']"
                                :total="$hero['total']"
                                :tone="$heroPalette['onDark'] ? 'dark' : 'default'"
                                class="pt-1"
                            />
                        </span>

                        {{-- Under way beats a clock: "Live" is the fact
                             that changes what you do next. --}}
                        @if ($hero['state'] === 'live')
                            <x-slate-status status="live" class="text-micro" />
                        @elseif ($hero['firstKick'])
                            {{-- Same words days out, a running mm:ss in the
                                 final hour. The palette's body class flows
                                 through the attribute bag, because the
                                 Woodshed's tile is black in both schemes and
                                 a clock nobody can read on it is a clock
                                 that is not there. --}}
                            <x-kick-clock :at="$hero['firstKick']" class="shrink-0 text-micro {{ $heroPalette['body'] }}" />
                        @endif
                    </div>

                    {{-- Render-guarded: an unwritten register is a quieter
                         hero, never a hole. --}}
                    @if ($heroZinger !== '')
                        <p class="text-sm {{ $heroPalette['body'] }}">{{ $heroZinger }}</p>
                    @endif

                    {{-- The AFFORDANCE stays plain in every register —
                         the joke is the line above it. --}}
                    {{-- `w-full` only while the card is one: uncapped, this
                         hero is ~648px wide and a button that fills it is
                         not an affordance, it is a wall. --}}
                    <flux:button :href="$heroHref" wire:navigate variant="primary" class="w-full md:w-auto md:self-start">
                        {{ $hero['made'] === 0 ? 'Make your picks' : 'Finish your picks' }}
                    </flux:button>

                    {{-- The rest, as a COUNT: the cards below carry their
                         own state, so the zone points at them rather than
                         drawing them again. A fact, plain in every
                         register, in the palette's body weight so it reads
                         on the Woodshed's black tile too. --}}
                    @if ($this->needsMore > 0)
                        <p class="text-micro {{ $heroPalette['body'] }}">and {{ $this->needsMore }} more below</p>
                    @endif
                </div>
            </div>
        @elseif ($this->allIn)
            {{-- ALL IN. The zone that asks for picks simply vanished when
                 the last entry landed, so a reader who finished on
                 Wednesday came back Saturday to a screen with no word
                 about it either way — and silence is the one answer a
                 pick'em screen cannot give about your picks.

                 NOT ANIMATED, deliberately: this is a STATE, not an
                 event. The pick surface's entry-in celebration fires on
                 the act that earns it and never again; a card that
                 animated on every visit would be a party thrown at a
                 reader for standing still. The bold lead-in and the check
                 are the non-color signal. --}}
            <div
                wire:key="all-in"
                role="status"
                class="flex items-center gap-2.5 rounded-xl bg-emerald-50 px-3 py-2.5 ring-1 ring-emerald-200 dark:bg-emerald-950/30 dark:ring-emerald-900"
            >
                <flux:icon.check-circle-fill variant="micro" class="size-4 shrink-0 text-emerald-600 dark:text-emerald-500" />
                <p class="min-w-0 flex-1 text-sm text-zinc-700 dark:text-zinc-300">
                    <span class="font-semibold text-zinc-900 dark:text-zinc-100">All in.</span>
                    {{ Voice::line('picks.allin.body') }}
                </p>
            </div>
        @endif

        {{-- No PRIVATE groups — which is not no memberships: one public
             seat must not suppress the pitch. The block below is
             unchanged; only the fork around it moved, because the stack
             it used to sit inside now renders for rooms-only readers too. --}}
        @if ($this->groupCards->isEmpty())
            {{-- FIRST RUN, and the two products said out loud. Path one
                 is the three doors, which remain the ONLY create
                 affordance — the old screen drew the wizard twice, once
                 as doors and once as a card underneath them. Path two is
                 the Lobby's own door, hoisted here so the alternative is
                 beside the choice instead of 600px below it. --}}
            <div class="flex flex-col gap-4">
                <flux:subheading class="font-semibold text-zinc-900 dark:text-zinc-100">Two ways to play</flux:subheading>

                <div class="flex flex-col gap-2">
                    <flux:subheading class="font-semibold text-zinc-900 dark:text-zinc-100">Start your own group</flux:subheading>
                    <flux:subheading>{{ Voice::line('picks.first_run.group') }}</flux:subheading>

                    <div class="flex flex-col gap-2 pt-1">
                        @foreach (ContestMode::cases() as $mode)
                            <x-mode-door wire:key="door-{{ $mode->value }}" :mode="$mode" />
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <flux:subheading class="font-semibold text-zinc-900 dark:text-zinc-100">Or take a seat this Saturday</flux:subheading>
                    <flux:subheading>{{ Voice::line('picks.rooms.subheading') }}</flux:subheading>

                    @include('partials.lobby-door')
                </div>
            </div>
        @endif

        {{-- MY GROUPS — the season-long, private half, under its own
             heading again (2026-09-01; reverses the 08-31 merge). The
             heading carries the KIND now — the same two sections the
             switcher's menu shows, so the taxonomy is one thing said in
             two places rather than fine print on every card. The escape
             to the wizard stays on the heading row for a reader who
             already has groups; on a first run the three mode doors
             above are the ONLY create affordance. --}}
        @if ($this->groupCards->isNotEmpty())
            <div class="flex flex-col gap-2" data-tour="seats">
                <div class="flex items-baseline justify-between gap-3">
                    <flux:subheading class="font-semibold text-zinc-900 dark:text-zinc-100">My Groups</flux:subheading>
                    <a href="{{ route('pickem.create') }}" wire:navigate class="text-micro shrink-0 font-medium text-blue-600 hover:underline dark:text-blue-400">
                        Start a group
                    </a>
                </div>
                <flux:subheading>{{ Voice::line('picks.groups.subheading') }}</flux:subheading>

                {{-- Two-up only from `xl`: this sits in the main column
                     beside the sidecar, so it is ~648px at `lg` and does
                     not have room for two seats until `xl`. `min-w-0` at
                     the call site because group-card's root carries none
                     and a grid item keeps its min-content width. --}}
                <div class="grid gap-3 xl:grid-cols-2">
                    @foreach ($this->groupCards as $card)
                        <x-group-card class="min-w-0" wire:key="play-{{ $card['group']->id }}" :card="$card" />
                    @endforeach
                </div>
            </div>
        @endif

        {{-- THE INVITE CODE, folded — under the groups it joins you to,
             and ONE unconditional site: a bad code has to open a form
             for a reader with no seats at all. Links are how a group
             travels now; the code is the spoken-word fallback. --}}
        <div
            x-data="{ open: @js($errors->has('code')) }"
            class="rounded-xl border border-zinc-200 dark:border-zinc-700"
        >
            <button
                type="button"
                x-on:click="open = ! open"
                x-bind:aria-expanded="open"
                class="flex w-full items-center justify-between gap-3 p-4 text-start"
            >
                <div class="min-w-0">
                    <p class="font-semibold">Have an invite code?</p>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('groups.join.subheading') }}</p>
                </div>
                <flux:icon name="chevron-down" variant="micro" class="shrink-0 text-zinc-400 transition-transform" x-bind:class="open && 'rotate-180'" />
            </button>

            <div x-show="open" x-cloak class="border-t border-zinc-100 p-4 dark:border-zinc-800/60">
                <form wire:submit="join" class="flex flex-col gap-3">
                    {{-- The format rule stays plain: 8 characters, told straight. --}}
                    <flux:input wire:model="code" label="Invite code" description="The 8-character code from your group." maxlength="8" autocomplete="off" class="uppercase" />
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="join" class="self-start">Join the group</flux:button>
                </form>
            </div>
        </div>

        {{-- WEEK N CONTESTS — the public half: this Saturday's rooms the
             reader is seated in, the always-open tables, and the ONE
             door to the Lobby, where a room is joined. The heading names
             the Saturday being sold and is SKIPPED when the calendar has
             no week — never the cards, never the door, never a
             substituted week. On a first run the door has already
             rendered beside the mode doors, so it stays out of here; and
             a rooms-only reader has no My Groups block, so the tour's
             `seats` stop anchors here instead of stepping over itself. --}}
        @if ($this->groupCards->isNotEmpty() || $this->roomCards->isNotEmpty() || $this->tableCards->isNotEmpty())
            @php $contestsHeading = $this->seats->weekLabel() === null ? null : $this->seats->weekLabel().' Contests'; @endphp

            <div class="flex flex-col gap-2" @if ($this->groupCards->isEmpty()) data-tour="seats" @endif>
                @if ($contestsHeading !== null)
                    <flux:subheading class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $contestsHeading }}</flux:subheading>
                    <flux:subheading>{{ Voice::line('picks.contests.subheading') }}</flux:subheading>
                @endif

                @if ($this->roomCards->isNotEmpty() || $this->tableCards->isNotEmpty())
                    <div class="grid gap-3 xl:grid-cols-2">
                        @foreach ($this->roomCards->concat($this->tableCards) as $card)
                            <x-group-card class="min-w-0" wire:key="play-{{ $card['group']->id }}" :card="$card" />
                        @endforeach
                    </div>
                @endif

                @if ($this->groupCards->isNotEmpty())
                    @include('partials.lobby-door')
                @endif
            </div>
        @endif
        @endif

        {{-- ================================ RESULTS ================= --}}
        @if ($this->activeView === 'results')
        {{-- YOU WON A WEEK. The house's second celebration, and the first
             one that is not on the pick surface: everything else here
             reports, and a week you took should not arrive as a row in a
             list beside a week you lost.

             The entrance is spent ONCE per session against the wins
             themselves. A celebration fired by an ACT can live on a
             protected property for one response — this one ARRIVES, so
             replaying it on every navigation back to Results would be a
             party for a week the reader already knows they won. The icon
             and the words carry the state; the emerald is the third
             signal, never the only one. --}}
        @if ($this->payoff !== null)
            @php
                $payoffLine = $this->payoff->count() === 1
                    ? Voice::line('picks.payoff.banner', [
                        'group' => $this->payoff->first()->slate->contest->group->name,
                        'points' => $this->payoff->first()->final_points ?? 0,
                    ])
                    : Voice::line('picks.payoff.banner_many', ['count' => $this->payoff->count()]);
            @endphp

            @if ($payoffLine !== '')
                <div
                    wire:key="payoff-banner"
                    role="status"
                    class="flex items-center gap-2.5 rounded-xl bg-emerald-50 px-3 py-2.5 ring-1 ring-emerald-200 dark:bg-emerald-950/30 dark:ring-emerald-900 {{ $this->payoffFresh ? 'motion-safe:animate-entry-in' : '' }}"
                >
                    <flux:icon.trophy class="size-4 shrink-0 text-emerald-600 dark:text-emerald-500" />
                    <p class="min-w-0 flex-1 text-sm text-zinc-700 dark:text-zinc-300">{{ $payoffLine }}</p>
                </div>
            @endif
        @endif

        {{-- The Monday payoff, compact: last week's settled results while
             they are still the conversation. --}}
        @if ($this->lastWeek->isNotEmpty())
            <div class="flex flex-col gap-2">
                <flux:subheading class="font-semibold text-zinc-900 dark:text-zinc-100">Last week</flux:subheading>
                @foreach ($this->lastWeek as $entry)
                    <a
                        href="{{ $entry->slate->contest->group->isRoom() ? route('pickem.room', $entry->slate->contest->group_id) : route('pickem.group', $entry->slate->contest->group_id) }}"
                        wire:navigate
                        wire:key="settled-{{ $entry->id }}"
                        class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 px-4 py-2.5 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600"
                    >
                        <p class="min-w-0 truncate text-sm font-medium">{{ $entry->slate->contest->group->name }}</p>
                        {{-- WHERE YOU CAME IN, History's own sentence and
                             History's own precedence: the Winner badge
                             says it better than "1st of 9" does, so the
                             place only speaks for the weeks nobody won
                             for you. Points last, the way the archive
                             prints them. --}}
                        @php $place = $this->places[$entry->slate_id] ?? null; @endphp
                        <p class="flex shrink-0 items-center gap-2 text-sm">
                            @if ($entry->won)
                                <flux:badge size="sm" color="green">Winner</flux:badge>
                            @elseif ($place !== null)
                                <span class="tabular text-micro text-zinc-500">{{ Number::ordinal($place['place']) }} of {{ $place['of'] }}</span>
                            @endif
                            <span class="tabular font-semibold">{{ $entry->final_points ?? 0 }} pts</span>
                        </p>
                    </a>
                @endforeach
            </div>
        @else
            {{-- Nothing settled is not an empty screen to apologize for —
                 it is a Saturday that has not happened yet, and the line
                 says so rather than leaving the tab blank. --}}
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('picks.results.empty') }}</p>
        @endif
        @endif

        </div>

        {{-- THE SIDECAR. Every block below already sat at the FOOT of this
             screen, so below `lg` nothing moved: the column collapses and
             they land exactly where a phone reader has always found them.
             From `lg` they ride alongside instead of pushing the spine down
             the page — the same trade the game screen makes. The invite
             code and the Lobby door left here 2026-09-01 for the sections
             they belong to. Not sticky: the picks walk spotlights `how` in
             here, and it scrolls to a measured box. --}}
        <div class="flex flex-col gap-6">
        @if ($this->activeView === 'week')
        {{-- THE ROOMS THAT ARE OVER, as a door and never as cards.

             A public room is a TRANSIENT contest: one Saturday, then it
             dies. Stacking last week's three above a reader's own groups
             said they were already seated in this Saturday's public
             contests, when the whole point is that a fresh week starts
             with the decision unmade. So the played rooms leave the
             stack and keep exactly one thing here — a way back to them.

             The count is a projection of cards(); History is the screen
             that holds every week the reader has played, in the section
             strip already. --}}
        @if ($this->pastRooms->isNotEmpty())
            <x-link-row :href="route('pickem.history')" title="Rooms you've played">
                <span class="block pt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $this->pastRooms->count() }} finished {{ Str::plural('room', $this->pastRooms->count()) }} — your settled weeks are in History
                </span>
                @php $roomsPast = Voice::line('picks.rooms.past'); @endphp
                @if ($roomsPast !== '')
                    <span class="text-micro block pt-0.5 text-zinc-500 dark:text-zinc-400">{{ $roomsPast }}</span>
                @endif
            </x-link-row>
        @endif

        @endif

        {{-- THE LADDER belongs to Results — XP is what a settled week
             paid. It stays on a TABLESS first run too, because XP is
             earned before the first slate is and that reader has no
             Results tab to find it in. --}}
        @if ($this->activeView === 'results' || ! $this->hasTabs)
        {{-- THE LADDER, one row. The header chip has room for the rung
             and nothing else, so this is where the next one is named and
             the climb has a number on it. --}}
        @if ($this->rank !== null)
            <div class="flex flex-col gap-1.5 rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <div class="flex items-baseline justify-between gap-3">
                    <span class="min-w-0 truncate font-semibold">{{ $this->rank['name'] }}</span>
                    <span class="tabular shrink-0 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ number_format($this->walletXp) }} XP
                    </span>
                </div>

                @if ($this->rank['next'] !== null)
                    {{-- A share of the CURRENT rung's span, so the bar resets
                         at each promotion instead of creeping toward Legend
                         all season. --}}
                    <div class="h-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                        <div
                            class="h-full rounded-full bg-zinc-900 dark:bg-zinc-100"
                            style="width: {{ round($this->rank['progress'] * 100, 2) }}%"
                        ></div>
                    </div>
                    <p class="text-micro text-zinc-500 dark:text-zinc-400">
                        {{ Voice::line('rank.to_next', [
                            'remaining' => number_format($this->rank['remaining']),
                            'next' => $this->rank['next'],
                        ]) }}
                    </p>
                @else
                    {{-- No next rung. `remaining` is null here, never a zero
                         standing in for it — so the climb line is SKIPPED
                         rather than rendered as a finished bar with no name. --}}
                    <p class="text-micro text-zinc-500 dark:text-zinc-400">{{ Voice::line('rank.topped_out') }}</p>
                @endif
            </div>
        @endif
        @endif

        {{-- The archive, one row: Results is this week's payoff while it
             is still the conversation, History is every week that ever
             settled. --}}
        @if ($this->activeView === 'results')
            <x-link-row :href="route('pickem.history')" title="Season history">
                <span class="block pt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Every week you have played, and what it paid.</span>
            </x-link-row>
        @endif

        {{-- THE REFERENCE, on BOTH views: the rules are what you go
             looking for mid-week as readily as on a Sunday, and a door
             that only exists on one fork is a door somebody cannot find.
             Its own address rather than a disclosure here — this screen
             already carries the week, the seats, the payoff and the
             ladder, and the Lobby folded its own rules away for exactly
             that reason. --}}
        <x-link-row :href="route('pickem.how')" title="How this works" data-tour="how">
            <span class="block pt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Tallboys, the cooler, and what every room costs.</span>
        </x-link-row>
        </div>
        </div>
    @else
        @include('partials.pickem-promise')
    @endif

    {{-- The Picks walk, LAST at the page root for the same reason Home's is:
         a `fixed inset-0` overlay must never sit inside a sticky or
         backdrop-filter ancestor, which would resolve it against the parent
         instead of the viewport. --}}
    @if ($this->showTour)
        <livewire:tour walk="picks" />
    @endif
</div>
