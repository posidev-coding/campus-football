<?php

use App\Models\Article;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Services\CfbCalendar;
use App\Support\Scope;
use App\Support\TeamGlance;
use Livewire\Attributes\Computed;
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
    private const TEAM_COLUMNS = 'id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark';

    /**
     * The viewer's teams, favorite first, then follow order — the same
     * priority the scoreboard floats them in.
     */
    #[Computed]
    public function followedTeams()
    {
        $user = auth()->user();

        if ($user === null) {
            return collect();
        }

        $columns = ['teams.id', 'slug', 'location', 'display_name', 'short_display_name', 'abbreviation', 'color', 'alt_color', 'logo', 'logo_dark'];

        $followed = $user->followedTeams()
            ->orderByPivot('created_at')
            ->orderBy('teams.display_name')
            ->get($columns);

        $favorite = $user->favorite_team_id;

        // Union, not just a sort — a follow row written before SetFavoriteTeam
        // guaranteed one would otherwise drop the user's own team.
        if ($favorite !== null && ! $followed->contains('id', $favorite)) {
            $followed->push(Team::select($columns)->find($favorite));
        }

        return $followed
            ->filter()
            ->sortBy(fn (Team $team) => $team->id === $favorite ? 0 : 1)
            ->values();
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
                x-data="{ active: 0 }"
                class="flex flex-col gap-3"
            >
                <h2 class="sr-only">Your teams</h2>

                <div
                    x-ref="track"
                    x-init="
                        const cards = [...$refs.track.children]
                        const io = new IntersectionObserver((entries) => {
                            entries.forEach(e => { if (e.isIntersecting) active = cards.indexOf(e.target) })
                        }, { root: $refs.track, threshold: 0.6 })
                        cards.forEach(c => io.observe(c))
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
                </div>

                @if (count($this->glances) > 1)
                    <div class="flex justify-center gap-1.5">
                        @foreach ($this->glances as $i => $glance)
                            {{-- scrollIntoView with no behavior option defers
                                 to the track's CSS scroll-behavior, which is
                                 what motion-safe gates. --}}
                            <button
                                type="button"
                                @click="$refs.track.children[{{ $i }}].scrollIntoView({ inline: 'center', block: 'nearest' })"
                                :class="active === {{ $i }} ? 'bg-zinc-600 dark:bg-zinc-300' : 'bg-zinc-300 dark:bg-zinc-700'"
                                class="size-1.5 rounded-full transition-colors"
                                aria-label="Show {{ $glance['team']->placeName() }}"
                                wire:key="dot-{{ $glance['team']->id }}"
                            ></button>
                        @endforeach
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
        @else
            {{-- Not an empty state — the page still works below. One quiet
                 card invites them in, speaking in their register. --}}
            <flux:callout icon="pin-angle">
                <flux:callout.heading>Make it yours</flux:callout.heading>
                <flux:callout.text>{{ App\Support\Voice::line('home.follow_prompt') }}</flux:callout.text>
                <x-slot:actions>
                    <flux:button :href="route('account')" wire:navigate size="sm">Follow a team</flux:button>
                </x-slot:actions>
            </flux:callout>
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
