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
 * followed team, pinned favorite first, swiped horizontally — because a fan
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
     * @return list<array{team: Team, rank: ?int, record: ?array, conference: ?string, position: ?int, form: mixed, live: ?Game, next: ?Game, last: ?Game}>
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

        // Form and last result: the season's completed games, oldest first.
        // Scoped to the results year so this is ~65 rows for five teams, not
        // a decade of history.
        $seasonIds = Season::where('year', TeamGlance::year())->pluck('id');

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

        return $teams->map(function (Team $team) use ($completed, $pending, $records, $ranks, $conferences, $positions) {
            $involves = fn (Game $game) => $game->home_team_id === $team->id || $game->away_team_id === $team->id;

            $theirs = $completed->filter($involves);
            $form = $theirs->slice(-5)->values();

            return [
                'team' => $team,
                'rank' => $ranks[$team->id] ?? null,
                'record' => $records[$team->id] ?? null,
                'conference' => $conferences[$team->id] ?? null,
                'position' => $positions[$team->id] ?? null,
                'form' => $form,
                'live' => $pending->first(fn (Game $game) => $involves($game) && $game->isInProgress()),
                'next' => $pending->first(fn (Game $game) => $involves($game) && ! $game->isInProgress()),
                'last' => $theirs->last(),
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
    {{-- Search lives here now, not on a tab. The bar expands into a
         full-screen panel in place; from `sm` up the header's ⌘K palette
         takes over and the bar retires. --}}
    <livewire:search-panel />

    @auth
        {{-- Renders with zero teams too: the swiper then holds a single empty
             slot, which IS the onboarding. A separate "go to Account" callout
             sent people away from the page they were trying to fill. --}}
        @if ($this->glances !== [] || $this->canFollowMore)
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
                x-data="{ active: 0 }"
                class="flex flex-col gap-3"
            >
                <h2 class="sr-only">Your teams</h2>

                <div
                    x-ref="track"
                    x-init="
                        /*
                         * Re-observed on every childList change, not captured
                         * once: quick-add inserts a card mid-session, and an
                         * observer built from a one-time snapshot would never
                         * watch it — the dots would stop tracking the swipe
                         * the moment the feature was used. IntersectionObserver
                         * ignores a repeat observe(), so this stays idempotent.
                         */
                        const io = new IntersectionObserver((entries) => {
                            entries.forEach(e => {
                                if (e.isIntersecting) active = [...$refs.track.children].indexOf(e.target)
                            })
                        }, { root: $refs.track, threshold: 0.6 })

                        const watch = () => [...$refs.track.children].forEach(c => io.observe(c))

                        watch()
                        new MutationObserver(watch).observe($refs.track, { childList: true })
                    "
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
                    Every team's news is rendered up front and toggled by
                    Alpine — at most 5 teams × 5 articles. A Livewire round
                    trip per swipe would put a visible stall on the one
                    interaction that has to feel instant. x-cloak everywhere
                    except the first list, which must paint before Alpine.
                --}}
                @foreach ($this->glances as $i => $glance)
                    <div
                        x-show="active === {{ $i }}"
                        @if ($i > 0) x-cloak @endif
                        class="flex flex-col gap-2"
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
            </section>
        @endif
    @else
        <div class="flex flex-col gap-1">
            <flux:heading size="xl">{{ config('app.name') }}</flux:heading>
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
