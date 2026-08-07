<?php

use App\Livewire\Concerns\PicksTeams;
use App\Models\Article;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Services\CfbCalendar;
use App\Support\Scope;
use App\Support\TeamGlance;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

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
            $user->forceFill(['onboarded_at' => now()])->save();
        }

        unset($this->showOnboardingCta);
    }

    /**
     * Everything the page renders from the follow list is stale once a team
     * lands, whether it came from the swiper's own slot or the onboarding
     * overlay next door.
     */
    #[On('team-followed')]
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

        if ($teams->isEmpty()) {
            return [];
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
     * Five most recent articles per followed team, from ONE join query.
     *
     * Grouped by the pivot's team id because an article can belong to several
     * teams — a rivalry-week story should appear under both cards.
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

        // One relation load across every article that survived the take(5)s —
        // x-article-card renders team chips, and lazy loading is off. groupBy
        // demotes to a Support collection, which has no load(), so the kept
        // models are gathered back into an Eloquent one; the loaded relations
        // land on the same instances the groups hold.
        $kept = $rows->groupBy('for_team_id')->map(fn ($articles) => $articles->take(5));

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
        return Article::query()
            ->with('teams:id,slug,short_display_name,abbreviation,logo,logo_dark')
            ->newest()
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function hasLiveGame(): bool
    {
        return collect($this->glances)->contains(fn (array $glance) => $glance['live'] !== null);
    }
}; ?>

<div class="flex flex-col gap-6">
    {{-- The brand bar. Scrolls away; the search bar below it is what pins. --}}
    <x-home-nav />

    {{-- Search lives here now, not on a tab. The bar expands into a
         full-screen panel in place; from `sm` up the header's ⌘K palette
         takes over and the bar retires. --}}
    <livewire:search-panel />

    {{-- The guided flow. Rendered for everyone: a guest steps through account
         creation and lands in the same picker a signed-in user opens straight
         into. It is inert until something dispatches `start-onboarding`. --}}
    <livewire:onboarding />

    {{-- One blue button is the whole front door at zero teams. The swiper's
         own quiet slot takes over once they have at least one — that is a
         convenience for someone already onboarded, not a prompt. --}}
    @if ($this->showOnboardingCta)
        <x-onboarding-cta :guest="! auth()->check()" />
    @endif

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
                x-data="{
                    active: 0,
                    newsHeight: 0,

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
                    class="-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 [scrollbar-width:none] motion-safe:scroll-smooth [&::-webkit-scrollbar]:hidden"
                >
                    @foreach ($this->glances as $glance)
                        <x-team-glance-card
                            :glance="$glance"
                            class="w-full shrink-0 snap-center sm:w-[calc(50%-0.375rem)]"
                            wire:key="glance-{{ $glance['team']->id }}"
                        />
                    @endforeach

                    {{-- The empty slot, last: swipe past your teams and there
                         is always somewhere to add the next one, until five. --}}
                    @if ($this->canFollowMore)
                        <x-team-add-card
                            :first="$this->glances === []"
                            :remaining="App\Models\User::MAX_FOLLOWED_TEAMS - count($this->glances)"
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
                            <button
                                type="button"
                                @click="$refs.track.children[{{ $i }}].scrollIntoView({ inline: 'center', block: 'nearest' })"
                                :class="active === {{ $i }} ? 'bg-zinc-600 dark:bg-zinc-300' : 'bg-zinc-300 dark:bg-zinc-700'"
                                class="size-1.5 rounded-full transition-colors"
                                aria-label="{{ isset($this->glances[$i]) ? 'Show '.$this->glances[$i]['team']->placeName() : 'Add a team' }}"
                                wire:key="dot-{{ $i }}"
                            ></button>
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

    <x-pickem-teaser />

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

            <div class="grid gap-2 sm:grid-cols-2">
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

        @foreach ($this->news as $article)
            <x-article-card :article="$article" wire:key="home-news-{{ $article->id }}" />
        @endforeach
    </section>
</div>
