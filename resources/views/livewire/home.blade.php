<?php

use App\Models\Article;
use App\Models\Game;
use App\Services\CfbCalendar;
use App\Support\Scope;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The front door.
 *
 * Signed out it is a national view: what is on, who is ranked, what happened.
 * Signed in, the user's favourite team leads — their news first, then their
 * next game — because a college football fan opens an app to find out about one
 * team before they care about the other 135.
 */
new class extends Component
{
    #[Computed]
    public function team()
    {
        return auth()->user()?->favoriteTeam;
    }

    /**
     * The favourite team's news, from ESPN's dedicated per-team feed.
     *
     * Deliberately not "articles tagged with this team": a national Top 25
     * preview tags 25 of them, so tag-matching would show every fan the same
     * listicles. The `team=` feed is a genuinely different, curated set —
     * verified live, it shares only 5 of 50 articles with the general feed.
     */
    #[Computed]
    public function teamNews()
    {
        $team = $this->team;

        if ($team === null) {
            return collect();
        }

        return Article::query()
            ->with('teams:id,slug,short_display_name,abbreviation,logo,logo_dark')
            ->whereHas('teams', fn ($q) => $q->whereKey($team->id))
            ->newest()
            ->limit(5)
            ->get();
    }

    /**
     * The favourite team's next game, or its most recent one out of season.
     */
    #[Computed]
    public function teamGame()
    {
        $team = $this->team;

        if ($team === null) {
            return null;
        }

        $with = [
            'homeTeam:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark',
            'awayTeam:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark',
            'venue:id,name',
            'odds',
        ];

        return $team->games()->with($with)->where('completed', false)->orderBy('kickoff_at')->first()
            ?? $team->games()->with($with)->where('completed', true)->orderByDesc('kickoff_at')->first();
    }

    /**
     * The most recent slate, scoped to ranked teams — the games worth leading
     * with rather than all 80 of them.
     */
    #[Computed]
    public function games()
    {
        $calendar = app(CfbCalendar::class);
        $year = $calendar->resultsYear();
        $weekId = $calendar->defaultWeekId($year);

        if ($weekId === null) {
            return collect();
        }

        $ranked = Scope::teamIds(Scope::TOP_25, $year) ?? [];

        return Game::query()
            ->with([
                'homeTeam:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark',
                'awayTeam:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark',
                'venue:id,name',
                'odds',
            ])
            ->where('week_id', $weekId)
            ->when($ranked !== [], fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('home_team_id', $ranked)
                ->orWhereIn('away_team_id', $ranked)))
            ->orderBy('kickoff_at')
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
}; ?>

<div class="flex flex-col gap-6">
    @auth
        @if ($this->team)
            {{-- The user's team leads. --}}
            <section class="flex flex-col gap-3">
                <div class="flex items-center justify-between gap-2">
                    <x-team-link :team="$this->team" size="lg" class="min-w-0" />

                    <flux:button :href="route('team', $this->team)" wire:navigate size="sm" variant="ghost" class="shrink-0">
                        Team page
                    </flux:button>
                </div>

                @if ($this->teamGame)
                    <x-game-card :game="$this->teamGame" />
                @endif

                @forelse ($this->teamNews as $article)
                    <x-article-card :article="$article" compact wire:key="mynews-{{ $article->id }}" />
                @empty
                    <flux:callout icon="newspaper">
                        <flux:callout.heading>No news for {{ $this->team->short_display_name }}</flux:callout.heading>
                        <flux:callout.text>
                            Nothing synced yet. ESPN's feed only reaches back a few days.
                        </flux:callout.text>
                    </flux:callout>
                @endforelse
            </section>

            <flux:separator variant="subtle" />
        @else
            <flux:callout icon="star">
                <flux:callout.heading>Pick a team</flux:callout.heading>
                <flux:callout.text>
                    Choose a favourite and their news leads your home page.
                </flux:callout.text>
                <x-slot:actions>
                    <flux:button :href="route('account')" wire:navigate size="sm">Choose</flux:button>
                </x-slot:actions>
            </flux:callout>
        @endif
    @else
        <div class="flex flex-col gap-1">
            <flux:heading size="xl">{{ config('app.name') }}</flux:heading>
            <flux:subheading>Scores, stats and standings — every team, every week.</flux:subheading>
        </div>
    @endauth

    @if ($this->games->isNotEmpty())
        <section class="flex flex-col gap-2">
            <div class="flex items-baseline justify-between gap-2">
                <flux:subheading>Top 25 games</flux:subheading>
                <a href="{{ route('scoreboard') }}" wire:navigate class="text-micro text-zinc-500 hover:underline">
                    All scores
                </a>
            </div>

            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($this->games as $game)
                    <x-game-card :game="$game" wire:key="home-game-{{ $game->id }}" />
                @endforeach
            </div>
        </section>
    @endif

    @if ($this->news->isNotEmpty())
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
    @endif
</div>
