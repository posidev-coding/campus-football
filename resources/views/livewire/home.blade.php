<?php

use App\Livewire\Concerns\PicksTeams;
use App\Models\Article;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Services\CfbCalendar;
use App\Support\PlaceholderTeam;
use App\Support\Scope;
use App\Support\TeamGlance;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Laravel\Pennant\Feature;

/**
 * The front door.
 *
 * Signed out it is a national view: what is on, who is ranked, what happened.
 * Signed in it opens on the user's OWN teams — one at-a-glance card per
 * followed team in the order they chose, swiped horizontally — because a fan
 * opens a football app to find out about their teams before the other 130.
 *
 * Data is loaded once per CONCERN across all followed teams, never per card:
 * five cards must not become five times the queries. Everything a card needs
 * beyond its games comes from TeamGlance's cached maps.
 */
new class extends Component
{
    use PicksTeams;

    private const TEAM_COLUMNS = 'id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark';

    /**
     * The getting-started card, shown only when there is nothing to show and
     * they have not waved it away.
     *
     * A guest's dismissal lives in the session, which lapses naturally; a
     * signed-in user's is `onboarded_at`, already on `users` and until now
     * unused. Adding a team stamps it too, so the prompt cannot come back on
     * a page that has their team on it.
     */
    #[Computed]
    public function showOnboardingCta(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return ! session('onboarding.dismissed', false);
        }

        return $this->followedTeams->isEmpty() && ! $user->hasOnboarded();
    }

    public function dismissOnboarding(): void
    {
        $user = auth()->user();

        if ($user === null) {
            session(['onboarding.dismissed' => true]);
        } elseif (! $user->hasOnboarded()) {
            /*
             * Declining the front door declines the coach marks too. The
             * tour no longer waits for a first team (the placeholder card
             * gives it a target), so without this stamp the X would be
             * answered by an uninvited tour on the very next load. Account
             * keeps "Replay the tour" for anyone who changes their mind.
             */
            $user->forceFill([
                'onboarded_at' => now(),
                'tour_completed_at' => $user->tour_completed_at ?? now(),
            ])->save();
        }

        unset($this->showOnboardingCta);
    }

    /**
     * Whether this load is the one that lands the verify celebration —
     * set by VerifyEmailController's browser branch and the notice screen's
     * poll redirect, read once, never a query param (an install captures
     * the tab URL; the onboarding.moment lesson).
     */
    public function opensToVerified(): bool
    {
        return auth()->check() && session()->has('verify.moment');
    }

    /**
     * Replay flag from Account's "Replay the tour" — a URL param so the
     * button is a plain link and a replay is shareable in a bug report.
     */
    #[Url(as: 'tour', except: false)]
    public bool $tourReplay = false;

    /**
     * The guided tour mounts once the signup wizard's hand-off lands: signed
     * in, onboarded, never toured. No followed-team requirement anymore —
     * the Bandwagon State placeholder means a zero-team Home still has a
     * glance card for the coach marks to point at, so skipping the picker no
     * longer silently costs the walkthrough. Gated by the app's first
     * Pennant flag so it can be pulled without a deploy.
     */
    #[Computed]
    public function showTour(): bool
    {
        $user = auth()->user();

        if ($user === null || ! Feature::active('guided-tour')) {
            return false;
        }

        if ($this->tourReplay) {
            return true;
        }

        return $user->hasOnboarded() && ! $user->hasToured();
    }

    /**
     * Everything the page renders from the follow list is stale once a team
     * lands, whether it came from the swiper's own slot or the onboarding
     * overlay next door.
     *
     * `onboarding-finished` matters most on the SKIP path: the page rendered
     * before `onboarded_at` existed, so the tour is not yet in the DOM, and
     * the wizard's `start-tour` event lands on nothing. This re-render is
     * what mounts the tour — whose own autoStart() then finds the overlay
     * closed and begins, spotlighting the placeholder card.
     */
    /**
     * The guided tour finished or was skipped. Clearing the replay flag is
     * the load-bearing half: `showTour` short-circuits on `$tourReplay`, so
     * a tour reached from Account's "Replay the tour" would stay "showing"
     * after its own last card, and the verify callout it holds down would
     * never come back. Dropping it also strips `?tour=1` from the URL, so a
     * reload does not restart a walk the reader just closed.
     */
    #[On('tour-finished')]
    public function tourFinished(): void
    {
        $this->tourReplay = false;

        unset($this->showTour);
    }

    #[On('team-followed')]
    #[On('onboarding-finished')]
    public function refreshTeams(): void
    {
        unset(
            $this->followedTeams, $this->glances, $this->newsByTeam,
            $this->followable, $this->teamMatches, $this->canFollowMore, $this->hasLiveGame,
            $this->showOnboardingCta,
        );
    }

    protected function afterTeamAdded(Team $team): void
    {
        $this->refreshTeams();
    }

    /**
     * The viewer's teams, in the order they chose on Account.
     *
     * One `get()` and nothing else: `followedTeams()` orders by the pivot's
     * position, so the swipe order IS the user's order. This used to sort a
     * favorite to the front and union it in if it was somehow not followed —
     * both of which stopped being possible the moment order became the model.
     */
    #[Computed]
    public function followedTeams()
    {
        $user = auth()->user();

        if ($user === null) {
            return collect();
        }

        return $user->followedTeams()->get([
            'teams.id', 'slug', 'location', 'display_name', 'short_display_name',
            'abbreviation', 'color', 'alt_color', 'logo', 'logo_dark',
        ]);
    }

    /**
     * Everything each card says, from two game queries and the glance maps.
     *
     * No `trend`. The card carried a row of W/L pills built from the same
     * completed games, and it is deliberately gone rather than incidentally
     * gone — the scope bug above had emptied it, and simply fixing the scope
     * would have brought the pills back by themselves the week the season
     * kicks off. A card says who a team is, when they play next, and how the
     * last one went; five circles restating the last five is the row a glance
     * can most afford to lose.
     *
     * @return list<array{team: Team, rank: ?int, record: ?array, conference: ?string, position: ?int, live: ?Game, next: ?Game, last: ?Game}>
     */
    #[Computed]
    public function glances(): array
    {
        $teams = $this->followedTeams;

        /*
         * Zero follows, signed in: Bandwagon State. The placeholder keeps the
         * swiper — and with it the tour's `glance` anchor — on screen, and
         * costs nothing: `PlaceholderTeam::glance()` is a made model and
         * literals, no queries, so the one-team-vs-five query-parity
         * invariant holds at zero too. Guests keep the empty array; the
         * section is @auth and a balanceless visitor gets the front door.
         */
        if ($teams->isEmpty()) {
            return auth()->check() ? [PlaceholderTeam::glance()] : [];
        }

        $ids = $teams->pluck('id')->all();

        $with = [
            'homeTeam:'.self::TEAM_COLUMNS,
            'awayTeam:'.self::TEAM_COLUMNS,
        ];

        /*
         * The last result: completed games, oldest first.
         *
         * `resultsYear()` — the latest season that HAS games played — not
         * `TeamGlance::year()`, which is the season being played and in the
         * offseason contains nothing completed at all. Reading the glance
         * year here emptied this query all summer, and with it the last
         * result on every card. The two questions genuinely differ: the
         * header states who a team IS this season, this states what they
         * last DID, and in August that is a bowl from the season before.
         *
         * Still season-scoped, because the alternative is a decade of
         * history per team — this is ~65 rows for five teams.
         */
        $glanceYear = TeamGlance::year();
        $resultsYear = app(CfbCalendar::class)->resultsYear();

        $seasonIds = Season::where('year', $resultsYear)->pluck('id');

        $completed = Game::query()
            ->with($with)
            ->whereIn('season_id', $seasonIds)
            ->where('completed', true)
            ->where(fn ($q) => $q->whereIn('home_team_id', $ids)->orWhereIn('away_team_id', $ids))
            ->orderBy('kickoff_at')
            ->get();

        // Live and upcoming: NOT season-scoped, deliberately. In August the
        // results year is last season — every game in it complete — and the
        // next game belongs to the season that has not started counting yet.
        $pending = Game::query()
            ->with($with)
            ->where('completed', false)
            ->where(fn ($q) => $q->whereIn('home_team_id', $ids)->orWhereIn('away_team_id', $ids))
            ->where(fn ($q) => $q
                ->where('kickoff_at', '>=', now())
                ->orWhereIn('status', ['in', 'halftime', 'end-period']))
            ->orderBy('kickoff_at')
            ->get();

        $records = TeamGlance::records();
        $ranks = TeamGlance::ranks();
        $conferences = TeamGlance::conferenceNames();
        $positions = TeamGlance::standingPositions();

        return $teams->map(function (Team $team) use ($completed, $pending, $records, $ranks, $conferences, $positions, $glanceYear, $resultsYear) {
            $involves = fn (Game $game) => $game->home_team_id === $team->id || $game->away_team_id === $team->id;

            return [
                'team' => $team,
                'rank' => $ranks[$team->id] ?? null,
                'record' => $records[$team->id] ?? null,
                'conference' => $conferences[$team->id] ?? null,
                'position' => $positions[$team->id] ?? null,
                'live' => $pending->first(fn (Game $game) => $involves($game) && $game->isInProgress()),
                'next' => $pending->first(fn (Game $game) => $involves($game) && ! $game->isInProgress()),
                'last' => $completed->last($involves),
                /*
                 * The season the last result belongs to, and null when it is
                 * the one the header already describes.
                 *
                 * A card in August states a 0-0 record and then a loss, which
                 * without this reads as a contradiction rather than as last
                 * season's bowl. It cannot be derived from the game's DATE —
                 * a January bowl is played in one calendar year and belongs
                 * to the season before it, so "Jan 9" would be labelled 2026
                 * while being part of 2025.
                 */
                'lastSeason' => $resultsYear === $glanceYear ? null : $resultsYear,
            ];
        })->all();
    }

    /**
     * Three most recent articles per followed team, from ONE join query.
     *
     * Grouped by the pivot's team id because an article can belong to several
     * teams — a rivalry-week story should appear under both cards. Three,
     * not five: each panel ends in a "More {Place} news" door to the team
     * page instead of two more headlines.
     *
     * @return array<int, \Illuminate\Support\Collection<int, Article>>
     */
    #[Computed]
    public function newsByTeam(): array
    {
        $ids = $this->followedTeams->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        $rows = Article::query()
            ->join('article_team', 'article_team.article_id', '=', 'articles.id')
            ->whereIn('article_team.team_id', $ids)
            ->orderByDesc('articles.published_at')
            ->limit(150)
            ->get(['articles.*', 'article_team.team_id as for_team_id']);

        // One relation load across every article that survived the take(3)s —
        // x-article-card renders team chips, and lazy loading is off. groupBy
        // demotes to a Support collection, which has no load(), so the kept
        // models are gathered back into an Eloquent one; the loaded relations
        // land on the same instances the groups hold.
        $kept = $rows->groupBy('for_team_id')->map(fn ($articles) => $articles->take(3));

        (new \Illuminate\Database\Eloquent\Collection($kept->flatten(1)->all()))
            ->load('teams:id,slug,short_display_name,abbreviation,logo,logo_dark');

        return $kept->all();
    }

    /**
     * The week worth leading with, on the season we are actually in.
     *
     * `scoreboardYear()`, not `resultsYear()`: results stay on the last
     * season PLAYED, so all summer this section served 2025's bowl games —
     * finished, months old, under a "Top 25" heading. Same trap the team
     * page's schedule fell into.
     */
    #[Computed]
    public function week(): ?\App\Models\Week
    {
        $calendar = app(CfbCalendar::class);

        return \App\Models\Week::find($calendar->defaultWeekId($calendar->scoreboardYear()));
    }

    /**
     * Whether the season has a poll to filter by at all.
     *
     * The preseason AP does not land until mid-August, and `Scope::teamIds`
     * silently falls back to all of FBS without one — which would put a "Top
     * 25 games" heading over six arbitrary openers.
     */
    #[Computed]
    public function hasPoll(): bool
    {
        return Scope::hasRankings(app(CfbCalendar::class)->scoreboardYear());
    }

    /**
     * Six games: the ranked ones when a poll exists, else the best matchups
     * ESPN projects.
     *
     * `matchupQuality` is the right signal for unplayed games — `gameQuality`
     * is retrospective and absent before kickoff — and 76 of week one's 99
     * games carry one, so it beats "whichever six kick off first".
     */
    #[Computed]
    public function games()
    {
        $week = $this->week;

        if ($week === null) {
            return collect();
        }

        $ranked = $this->hasPoll
            ? (Scope::teamIds(Scope::TOP_25, app(CfbCalendar::class)->scoreboardYear()) ?? [])
            : [];

        return Game::query()
            ->with([
                'homeTeam:'.self::TEAM_COLUMNS,
                'awayTeam:'.self::TEAM_COLUMNS,
                'venue:id,name',
                'odds',
            ])
            ->where('week_id', $week->id)
            ->when($ranked !== [], fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('home_team_id', $ranked)
                ->orWhereIn('away_team_id', $ranked)))
            ->when(
                $ranked !== [],
                fn ($q) => $q->orderBy('kickoff_at'),
                // No poll: lead with the matchups worth watching. NULLs last,
                // so games ESPN has not modelled do not outrank ones it has.
                fn ($q) => $q
                    ->leftJoin('game_predictors as gp', 'gp.game_id', '=', 'games.id')
                    ->select('games.*')
                    ->orderByRaw('gp.matchup_quality IS NULL, gp.matchup_quality DESC')
                    ->orderBy('games.kickoff_at'),
            )
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function news()
    {
        // Three, not six: Home leads with the reader's own state now, and
        // the full feed is one tap away behind the unconditional "More".
        return Article::query()
            ->with('teams:id,slug,short_display_name,abbreviation,logo,logo_dark')
            ->newest()
            ->limit(3)
            ->get();
    }

    /**
     * THE PICKS STRIP — the viewer's own slates in one PickemPulse read,
     * urgency-ordered: cards still needing picks first (live before
     * upcoming, soonest kickoff first), finished entries after. Empty for
     * guests, the closed flag, or a week with nothing published — the
     * strip then yields the slot back to the teaser.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function pickemCards()
    {
        if (auth()->guest()) {
            return collect();
        }

        return \App\Support\PickemPulse::cards(auth()->user())
            ->sortBy(fn (array $card) => [
                $card['entryIn'] ? 1 : 0,
                $card['state'] === 'live' ? 0 : 1,
                // A missing kickoff is missing data — it sorts last, never
                // "kicks at the epoch".
                $card['firstKick']?->getTimestamp() ?? PHP_INT_MAX,
            ])
            ->values();
    }

    /**
     * The foot door's count — the lean COUNT, never the inventory (the
     * same read My Picks' lobby door makes). Asked only when the flagged
     * branch renders.
     */
    #[Computed]
    public function roomsOpen(): int
    {
        return \App\Support\Lobby::openRoomCount(auth()->user());
    }

    /**
     * THE ONE THING TO DO NEXT — PickemPulse's ladder, or null when
     * there is nothing worth saying (and null renders nothing).
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function nextUp(): ?array
    {
        return auth()->guest() ? null : \App\Support\PickemPulse::nudge(auth()->user());
    }

    #[Computed]
    public function hasLiveGame(): bool
    {
        return collect($this->glances)->contains(fn (array $glance) => $glance['live'] !== null);
    }
}; ?>

<div class="flex flex-col gap-6">
    {{-- The brand bar. Scrolls away; the search bar below it is what pins.
         The slot carries the gamification chips it was reserved for — signed
         in only, because the balance is yours. --}}
    <x-home-nav>
        @auth
            <x-wallet-chips data-tour="wallet" />
        @endauth
    </x-home-nav>

    {{-- Search lives here now, not on a tab. The bar expands into a
         full-screen panel in place; from `sm` up the header's ⌘K palette
         takes over and the bar retires. --}}
    <livewire:search-panel />

    {{-- The guided flow. Rendered for everyone: a guest steps through account
         creation and lands in the same picker a signed-in user opens straight
         into. It is inert until something dispatches `start-onboarding`. --}}
    <livewire:onboarding />

    {{-- The verify nudge leads the page for an unverified account: it pays
         (the first Tallboy and XP), and the clock under it is real. The
         component renders nothing for guests and the verified.

         It also stands down for the length of the guided tour — the same
         first-run attention budget the install and push banners wait on
         below, and for a harder reason than tidiness. The tour's coach marks
         are client-side geometry measured against a live page, and this row
         sits directly ABOVE the swiper the first mark points at: a nudge
         that resolves its cloak, polls, or is dismissed mid-walk moves the
         glance card out from under its own highlight. Reported from a real
         phone on 2026-08-31 as the shading not containing the card. The
         tour's exit dispatches `tour-finished`, which is what puts the row
         back — no navigation required. --}}
    @unless ($this->showTour)
        <livewire:verify-callout @email-verified="$refresh" />
    @endunless

    {{-- The nudge's send-off: a one-load emerald row in the same slot,
         behind the `verify.moment` flash the verify click set. The server
         value feeds BOTH the Alpine initial state and the conditional cloak
         (the opensToMoment() pattern), so pre-paint cannot disagree with
         Alpine; after boot the row is Alpine's alone, and no later morph
         (Home's live poll) can yank it mid-read. No persistence — the flash
         makes it one-time by construction. The in-app POLL flip shows no
         celebration on purpose: no flash rides an update request, and the
         chips ticking up plus the nudge vanishing are the app's feedback. --}}
    <div
        x-data="{ celebrated: @js($this->opensToVerified()) }"
        @if (! $this->opensToVerified()) x-cloak @endif
        x-show="celebrated"
        data-verified-celebration
        class="flex items-center gap-2.5 rounded-xl bg-emerald-50 py-2 pr-1 pl-3 ring-1 ring-emerald-200 dark:bg-emerald-950/30 dark:ring-emerald-900"
    >
        <flux:icon.check-badge class="size-4 shrink-0 text-emerald-600 dark:text-emerald-500" />

        <p class="min-w-0 flex-1 text-sm text-zinc-700 dark:text-zinc-300">
            {{ App\Support\Voice::line('verify.celebration.body') }}
        </p>

        <flux:button
            x-on:click="celebrated = false"
            size="xs"
            square
            variant="ghost"
            icon="x-mark"
            class="shrink-0"
            aria-label="Dismiss"
        />
    </div>

    {{-- THE NEXT-UP SLOT: the one thing to do next, from PickemPulse's
         ladder. One card, tone-tinted, whole-card CTA. It yields to the
         onboarding CTA below (following a team IS the next thing at zero
         follows), and the resolver itself stays silent for unverified
         readers — the verify callout above never has to compete. --}}
    @auth
        @if (! $this->showOnboardingCta && $this->nextUp !== null)
            <x-next-up :nudge="$this->nextUp" />
        @endif
    @endauth

    {{-- One blue button is the whole front door at zero teams. The swiper's
         own quiet slot takes over once they have at least one — that is a
         convenience for someone already onboarded, not a prompt. --}}
    @if ($this->showOnboardingCta)
        <x-onboarding-cta :guest="! auth()->check()" />
    @endif

    {{-- The install pitch waits for demonstrated interest: members only, and
         only once the tour has finished making the app's own case. Guests
         never see it — the front door above outranks the shell. Hidden inside
         the installed app; dismissal is per user, per device. --}}
    @auth
        @if (auth()->user()->hasToured())
            <x-install-banner />

            {{-- The installed-app counterpart, in the same slot with the
                 same demonstrated-interest gate: the tour and the verify
                 callout own the first-run attention budget, so the push
                 pitch waits its turn exactly like the install pitch does.
                 Stylesheet-disjoint from the row above — data-install-only
                 vs data-standalone-only — so at most one ever renders. --}}
            <x-push-banner />
        @endif
    @endauth

    @auth
        @if ($this->glances !== [])
            {{--
                The team swiper. Native scroll-snap IS the animation: no JS
                tween, no library — momentum scrolling is what makes it feel
                buttery, and it matches the week scroller. The active index
                comes from an IntersectionObserver rather than a scroll
                listener, so the dots and the news list keep up mid-fling.

                Polls only while one of the teams is actually playing, reading
                our own database — never ESPN.
            --}}
            <section
                @if ($this->hasLiveGame) wire:poll.30s.visible @endif
                @touchstart.passive="beginSwipe($event)"
                @touchend.passive="endSwipe($event)"
                x-data="{
                    active: 0,
                    newsHeight: 0,
                    swipeFrom: null,

                    /*
                     * Re-observed on every childList change, not captured once:
                     * quick-add inserts a card mid-session, and an observer built
                     * from a one-time snapshot would never watch it — the dots
                     * would stop tracking the swipe the moment the feature was
                     * used. IntersectionObserver ignores a repeat observe(), so
                     * this stays idempotent.
                     */
                    trackCards() {
                        const io = new IntersectionObserver((entries) => {
                            entries.forEach(e => {
                                if (! e.isIntersecting) return
                                this.active = [...this.$refs.track.children].indexOf(e.target)
                                this.measureNews()
                                this.settleNews()
                            })
                        }, { root: this.$refs.track, threshold: 0.6 })

                        const watch = () => [...this.$refs.track.children].forEach(c => io.observe(c))

                        watch()
                        new MutationObserver(watch).observe(this.$refs.track, { childList: true })

                        // Articles reflow at other widths and the panel heights
                        // move with them, so the height is re-measured from the
                        // content rather than cached from first paint.
                        if (this.$refs.newsTrack) {
                            new ResizeObserver(() => this.measureNews()).observe(this.$refs.newsTrack)
                        }

                        this.measureNews()
                    },

                    /** One panel plus one gap — the distance between snap points. */
                    stepOf(el) {
                        return el.children.length
                            ? el.children[0].offsetWidth + (parseFloat(getComputedStyle(el).columnGap) || 0)
                            : 0
                    },

                    /**
                     * True only while one card fills the viewport, which is the
                     * width the two tracks share a geometry at.
                     */
                    tracksAlign() {
                        return Math.abs(this.stepOf(this.$refs.track) - this.stepOf(this.$refs.newsTrack)) <= 1
                    },

                    /*
                     * The news follows the CARDS, frame for frame — scrollLeft
                     * copied straight across, because at this width the panels
                     * and gaps are identical and the mapping is 1:1.
                     *
                     * Driven off scroll rather than off the active index because
                     * the swipe is a drag with momentum: anything keyed on the
                     * snap lands after the gesture and reads as a lag.
                     */
                    syncNews() {
                        if (! this.$refs.newsTrack || ! this.tracksAlign()) {
                            return
                        }

                        this.$refs.newsTrack.scrollLeft = this.$refs.track.scrollLeft
                    },

                    /*
                     * From `sm` the cards go TWO-UP at half width while a news
                     * panel is still full width, and there mirroring is not
                     * merely inexact — it is impossible. Measured at 768 with
                     * five teams: panels 3 and 4 both sit at the track's maximum
                     * scroll, so no function of scrollLeft can tell them apart.
                     * Two visible cards cannot address one news list.
                     *
                     * So that width follows the ACTIVE INDEX instead, smoothly.
                     * You do not swipe a two-up grid; you glance at it.
                     */
                    settleNews() {
                        const news = this.$refs.newsTrack

                        if (! news || this.tracksAlign()) {
                            return
                        }

                        news.scrollTo({ left: this.active * this.stepOf(news), behavior: 'smooth' })
                    },

                    /*
                     * The track is a flex row, so without an explicit height it
                     * takes the TALLEST panel and a team with one article sits
                     * under four articles of whitespace. Measured from the active
                     * panel and eased.
                     *
                     * `items-start` on the track is what makes this measurable at
                     * all: flex items stretch to the container by default, so a
                     * panel inside a height-constrained track would report the
                     * height we just set it — the measurement would feed itself.
                     */
                    measureNews() {
                        const panel = this.$refs.newsTrack?.children[this.active]

                        this.newsHeight = panel ? panel.offsetHeight : 0
                    },

                    /*
                     * Swipe ANYWHERE in this section — the dots row, the news
                     * panels, the subheadings — not only on the card track.
                     * The news track is overflow-hidden on purpose (a follower
                     * can never disagree with the cards about which team is
                     * showing), so it cannot scroll; these two methods give
                     * the dead zones the gesture instead, by converging on the
                     * dots' own scrollIntoView idiom. The card track drives,
                     * the observer updates `active`, the news follows — no
                     * second sync path, nothing new to disagree.
                     *
                     * Touches that BEGIN on the card track are ignored: it
                     * already swipes natively, and a second handler on top of
                     * a native drag would advance twice per gesture.
                     */
                    beginSwipe(e) {
                        this.swipeFrom = e.target.closest('[x-ref=track]')
                            ? null
                            : { x: e.touches[0].clientX, y: e.touches[0].clientY }
                    },

                    /*
                     * Act only on clear horizontal intent — a finger travels
                     * diagonally, and a vertical page scroll must never
                     * advance the swiper. `.passive` on both bindings means
                     * nothing here can preventDefault, so native scrolling
                     * and link taps inside the news cards stay native (a drag
                     * past slop already suppresses the click on its own).
                     */
                    endSwipe(e) {
                        if (! this.swipeFrom) return

                        const dx = e.changedTouches[0].clientX - this.swipeFrom.x
                        const dy = e.changedTouches[0].clientY - this.swipeFrom.y
                        this.swipeFrom = null

                        if (Math.abs(dx) < 48 || Math.abs(dx) <= Math.abs(dy) * 1.5) return

                        this.$refs.track.children[this.active + (dx < 0 ? 1 : -1)]
                            ?.scrollIntoView({ inline: 'center', block: 'nearest' })
                    },
                }"
                class="flex flex-col gap-3"
            >
                <h2 class="sr-only">Your teams</h2>

                {{--
                    The observer lives in `x-data` above and is only CALLED from
                    here, and that split is load-bearing rather than tidiness.

                    Alpine compiles an expression as `__self.result = <expr>`,
                    and only wraps it in an IIFE when the expression STARTS with
                    `let`/`const` (verified in the vendored bundle). This body
                    opened with a block comment, so the heuristic missed it,
                    `result = const io = …` was a SyntaxError, and the whole
                    `x-init` silently never ran — no observer, `active` frozen
                    at 0, dots that never moved. Nothing visibly errors; the
                    feature is just inert.

                    A method body has no such constraint, and an object literal
                    can carry the comment. `x-init` stays HERE rather than on
                    the section because `$refs.track` has to exist when it runs:
                    Alpine walks the tree top-down, so a parent's `x-init` fires
                    before its children register their refs. On this element the
                    ref is its own, and `ref` is ordered before `init`.
                --}}
                <div
                    x-ref="track"
                    x-init="trackCards()"
                    @scroll="syncNews()"
                    data-tour="glance"
                    class="-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 [scrollbar-width:none] motion-safe:scroll-smooth [&::-webkit-scrollbar]:hidden"
                >
                    @foreach ($this->glances as $glance)
                        {{-- Bandwagon State gets its own card, never the
                             glance card wearing a costume — the real one is
                             a factual surface whose header links to a team
                             page this team does not have. --}}
                        @if ($glance['placeholder'] ?? false)
                            <x-team-placeholder-card
                                class="w-full shrink-0 snap-center sm:w-[calc(50%-0.375rem)]"
                                wire:key="glance-placeholder"
                            />
                        @else
                            <x-team-glance-card
                                :glance="$glance"
                                class="w-full shrink-0 snap-center sm:w-[calc(50%-0.375rem)]"
                                wire:key="glance-{{ $glance['team']->id }}"
                            />
                        @endif
                    @endforeach

                    {{-- The empty slot, last: swipe past your teams and there
                         is always somewhere to add the next one, until five.
                         Counted from FOLLOWS, not glances — the placeholder
                         is not a team, and it must not eat a slot or make
                         this copy claim four when all five are free. --}}
                    @if ($this->canFollowMore)
                        <x-team-add-card
                            :first="$this->followedTeams->isEmpty()"
                            :remaining="App\Models\User::MAX_FOLLOWED_TEAMS - $this->followedTeams->count()"
                            :query="$teamQuery"
                            :matches="$this->teamMatches"
                            :error="$followError"
                            class="w-full shrink-0 snap-center sm:w-[calc(50%-0.375rem)]"
                            wire:key="add-slot"
                        />
                    @endif
                </div>

                @php $slots = count($this->glances) + ($this->canFollowMore ? 1 : 0); @endphp

                @if ($slots > 1)
                    <div class="flex justify-center gap-1.5">
                        @for ($i = 0; $i < $slots; $i++)
                            {{-- scrollIntoView with no behavior option defers
                                 to the track's CSS scroll-behavior, which is
                                 what motion-safe gates. --}}
                            {{-- The tap target grew, the ink did not: a 6px
                                 dot was the smallest control in the app, so
                                 the BUTTON is 22px of padding around it. --}}
                            <button
                                type="button"
                                @click="$refs.track.children[{{ $i }}].scrollIntoView({ inline: 'center', block: 'nearest' })"
                                class="-m-1 p-2"
                                aria-label="{{ isset($this->glances[$i]) ? 'Show '.$this->glances[$i]['team']->placeName() : 'Add a team' }}"
                                wire:key="dot-{{ $i }}"
                            >
                                <span
                                    :class="active === {{ $i }} ? 'bg-zinc-600 dark:bg-zinc-300' : 'bg-zinc-300 dark:bg-zinc-700'"
                                    class="block size-1.5 rounded-full transition-colors"
                                ></span>
                            </button>
                        @endfor
                    </div>
                @endif

                {{--
                    Every team's news is rendered up front — at most 5 teams ×
                    5 articles. A Livewire round trip per swipe would put a
                    visible stall on the one interaction that has to feel
                    instant.

                    It is a TRACK rather than a stack of `x-show` toggles, and
                    it mirrors the card track's scroll (see `syncNews`) so the
                    news slides with the cards under the finger instead of
                    swapping after the snap. Its own overflow is hidden: it is
                    a follower, and letting it scroll independently would let
                    the two disagree about which team is showing.

                    No `x-cloak` here, unlike the toggles this replaced — every
                    panel is meant to be in the layout, and the one that shows
                    before Alpine boots is panel 0 at scrollLeft 0, which is
                    the right one.
                --}}
                <div
                    x-ref="newsTrack"
                    :style="newsHeight ? `height: ${newsHeight}px` : null"
                    class="-mx-4 flex items-start gap-3 overflow-hidden px-4 transition-[height] duration-300 ease-out motion-reduce:transition-none"
                >
                    @foreach ($this->glances as $i => $glance)
                        {{-- `inert` on everything but the active panel. These
                             are clipped rather than `display: none` now, so
                             without it every off-screen headline stays in the
                             tab order and in the accessibility tree. --}}
                        @if ($glance['placeholder'] ?? false)
                            {{-- Bandwagon State's panel keeps the two tracks
                                 mapped index for index — card 0 must pair
                                 with panel 0 — and carries the joke's second
                                 verse instead of a news lookup for a team id
                                 no article will ever tag. --}}
                            <div
                                :inert="active !== {{ $i }}"
                                class="flex w-full shrink-0 flex-col gap-2"
                                wire:key="team-news-placeholder"
                            >
                                <flux:subheading>{{ $glance['team']->placeName() }} news</flux:subheading>

                                <flux:callout icon="newspaper">
                                    <flux:callout.text>
                                        {{ App\Support\Voice::line('placeholder.news', ['name' => App\Support\PlaceholderTeam::LOCATION]) }}
                                    </flux:callout.text>
                                </flux:callout>
                            </div>
                            @continue
                        @endif

                        <div
                            :inert="active !== {{ $i }}"
                            class="flex w-full shrink-0 flex-col gap-2"
                            wire:key="team-news-{{ $glance['team']->id }}"
                        >
                            <flux:subheading>{{ $glance['team']->placeName() }} news</flux:subheading>

                            @forelse ($this->newsByTeam[$glance['team']->id] ?? [] as $article)
                                <x-article-card :article="$article" compact wire:key="tn-{{ $glance['team']->id }}-{{ $article->id }}" />
                            @empty
                                <flux:callout icon="newspaper">
                                    <flux:callout.heading>No news for {{ $glance['team']->short_display_name }}</flux:callout.heading>
                                    <flux:callout.text>
                                        Nothing synced yet. ESPN's feed only reaches back a few days.
                                    </flux:callout.text>
                                </flux:callout>
                            @endforelse

                            {{-- The rest of the team's feed lives on its own
                                 page — a door, not two more headlines. --}}
                            @if (($this->newsByTeam[$glance['team']->id] ?? collect())->isNotEmpty())
                                <a
                                    href="{{ route('team', $glance['team']) }}?tab=news"
                                    wire:navigate
                                    class="text-micro self-start font-medium text-zinc-500 hover:underline"
                                >
                                    More {{ $glance['team']->placeName() }} news
                                </a>
                            @endif
                        </div>
                    @endforeach

                    {{-- The add slot's counterpart. The card track has one more
                         panel than there are teams, and the two tracks are
                         mapped index for index — without this the news would
                         run out before the cards do and sit a panel behind for
                         the length of the swipe. --}}
                    @if ($this->canFollowMore)
                        <div class="w-full shrink-0" aria-hidden="true" wire:key="team-news-add"></div>
                    @endif
                </div>
            </section>
        @endif
    @else
        {{-- The name used to be a heading here. It is now in the nav directly
             above at base and in the layout header from `sm` up, so printing it
             again put the same two words twice on one screen 40px apart. The
             tagline leads instead, and the h1 goes sr-only — the same call
             every League screen already makes. --}}
        <div class="flex flex-col gap-1">
            <h1 class="sr-only">{{ App\Support\Brand::name() }}</h1>
            <flux:subheading>Scores, stats and standings — every team, every week.</flux:subheading>
        </div>
    @endauth

    {{-- The league's Saturday, in one fixed slot: "your teams" above it and
         the week's headline below. Renders nothing at all out of season. --}}
    <x-gameday-card />

    {{-- THE PICKS SLOT. A member with slates on the card gets their OWN
         state — the same compact rows My Picks renders — in place of the
         static teaser; everyone else keeps the teaser exactly as it was.
         The section inherits the teaser's tour anchor: the strip only
         renders while the flag is open for this viewer, which is the same
         gate the teaser's own data-tour wears. --}}
    @if ($this->pickemCards->isNotEmpty())
        <section class="flex flex-col gap-2" data-tour="room">
            <div class="flex items-baseline justify-between gap-2">
                <flux:subheading>Your picks</flux:subheading>
                <a href="{{ route('pickem.home') }}" wire:navigate class="text-micro text-zinc-500 hover:underline">
                    All your picks
                </a>
            </div>

            @foreach ($this->pickemCards->take(2) as $card)
                <x-slate-row
                    :card="$card"
                    :tone="! $card['entryIn'] && in_array($card['state'], ['upcoming', 'live'], true) ? 'needs' : 'default'"
                    wire:key="home-slate-{{ $card['group']->id }}"
                />
            @endforeach
        </section>
    @else
        <x-pickem-teaser />
    @endif

    @if ($this->games->isNotEmpty())
        <section class="flex flex-col gap-2">
            <div class="flex items-baseline justify-between gap-2">
                {{-- Honest about what the six games are. Without a poll there
                     is no Top 25 to filter by, so calling them that would be
                     the same lie the scope filter is disabled to avoid — they
                     are the week's best projected matchups instead. --}}
                <flux:subheading>
                    {{ $this->hasPoll ? 'Top 25 games' : 'Best of '.($this->week?->name ?? 'the week') }}
                </flux:subheading>
                <a href="{{ route('scoreboard') }}" wire:navigate class="text-micro text-zinc-500 hover:underline">
                    All scores
                </a>
            </div>

            {{-- Three across from `xl`, where the column beside the rail is
                 936px and a card is still 306px — the same three-up the
                 scoreboard's day groups use, and the same floor. --}}
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                {{-- Dated: this grid is a flat six across a Thursday-to-Saturday
                     week, with no day headings to say which is which. --}}
                @foreach ($this->games as $game)
                    <x-game-card :game="$game" date wire:key="home-game-{{ $game->id }}" />
                @endforeach
            </div>
        </section>
    @endif

    {{-- The heading and its "More" link render unconditionally: with Home's
         section strip gone this link is the News screen's only path on a
         phone, and an empty articles table must not make a whole screen
         unreachable. --}}
    <section class="flex flex-col gap-2">
        <div class="flex items-baseline justify-between gap-2">
            <flux:subheading>Latest news</flux:subheading>
            <a href="{{ route('news') }}" wire:navigate class="text-micro text-zinc-500 hover:underline">
                More
            </a>
        </div>

        {{-- Gridded from `md`, where the rail has not arrived yet and the
             column is the whole shell: three full-width rows put a headline
             in the left third and nothing in the other two. The same card
             already runs four across on /news, and carries its own
             `min-w-0` for exactly this use. --}}
        <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->news as $article)
                <x-article-card :article="$article" wire:key="home-news-{{ $article->id }}" />
            @endforeach
        </div>
    </section>

    {{-- THE FOOT DOOR. The page never ends on somebody else's articles —
         the last thing Home says is a way onward: the Lobby while the
         flag is open, the League otherwise. No data-tour here; the picks
         slot above already carries the room anchor. --}}
    @auth
        @php $footOpen = config('cfb.pickem_open') === true || (bool) auth()->user()?->isAdmin(); @endphp

        @if ($footOpen)
            <x-link-row :href="route('pickem.lobby')" title="Find a room">
                <span class="block pt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                    @if ($this->roomsOpen > 0)
                        {{ $this->roomsOpen }} public {{ Str::plural('room', $this->roomsOpen) }} open this Saturday
                    @else
                        {{ App\Support\Voice::line('lobby.publics.empty') }}
                    @endif
                </span>
            </x-link-row>
        @else
            <x-link-row :href="route('standings')" title="Standings and rankings">
                <span class="block pt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Every team, every conference, every week.</span>
            </x-link-row>
        @endif
    @endauth

    {{-- The guided tour, LAST at the page root: `fixed inset-0` must never
         sit inside a sticky/backdrop-filter ancestor (the search-panel
         lesson), and being last keeps it above nothing it should be under. --}}
    @if ($this->showTour)
        <livewire:tour />
    @endif
</div>
