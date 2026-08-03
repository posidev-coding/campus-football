<?php

use App\Models\Conference;
use App\Models\Game;
use App\Models\Season;
use App\Models\TeamSeason;
use App\Models\Week;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The scoreboard reads the database and cache only. It never calls ESPN.
 *
 * v3 called ESPN inside render(), so a `wire:poll` on a live game issued one
 * upstream request per viewer per 15 seconds. Here the scheduled live tier does
 * one request a minute for everybody, and this component just reads what it
 * wrote — so viewer count has no effect on upstream load at all.
 *
 * Note that nothing cached here is an Eloquent model. Caching model collections
 * round-trips through Redis as __PHP_Incomplete_Class, which fails only on the
 * SECOND request — the first populates the cache and looks fine. Plain arrays
 * are also smaller and cheaper to hydrate.
 */
new class extends Component
{
    #[Url]
    public ?int $year = null;

    #[Url]
    public ?int $week = null;

    /*
     * Typed as a string, not ?int. A querystring value is always a string, and
     * the "All conferences" option submits an empty one — assigning that to an
     * ?int property is a hard type error. Cast at the point of use instead.
     */
    #[Url]
    public string $conference = '';

    public function mount(): void
    {
        $this->year ??= config('cfb.season');
        $this->week ??= $this->defaultWeek();
    }

    private function defaultWeek(): ?int
    {
        $season = Season::where('year', $this->year)->where('type', Season::REGULAR)->first();

        if ($season === null) {
            return null;
        }

        $now = now();

        return Week::where('season_id', $season->id)
            ->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now)
            ->value('number')
            ?? Week::where('season_id', $season->id)->max('number');
    }

    #[Computed]
    public function seasons(): array
    {
        return Cache::remember('scoreboard:seasons', 3600, fn () => Season::query()
            ->where('type', Season::REGULAR)
            ->orderByDesc('year')
            ->pluck('year', 'year')
            ->all());
    }

    /**
     * @return list<array{number:int, name:string}>
     */
    #[Computed]
    public function weeks(): array
    {
        $season = Season::where('year', $this->year)->where('type', Season::REGULAR)->first();

        if ($season === null) {
            return [];
        }

        return Cache::remember(
            "scoreboard:weeks:{$season->id}",
            3600,
            fn () => Week::where('season_id', $season->id)
                ->orderBy('number')
                ->get(['number', 'name'])
                ->map(fn (Week $w) => ['number' => $w->number, 'name' => $w->name])
                ->all()
        );
    }

    /**
     * Conferences that actually had FBS teams this season — read through
     * team_seasons, because membership is season-scoped.
     *
     * @return list<array{id:int, name:string}>
     */
    #[Computed]
    public function conferences(): array
    {
        return Cache::remember(
            "scoreboard:conferences:{$this->year}",
            3600,
            fn () => Conference::query()
                ->whereIn('id', TeamSeason::where('season_year', $this->year)
                    ->where('classification', 'FBS')
                    ->whereNotNull('conference_id')
                    ->distinct()
                    ->pluck('conference_id'))
                ->where('is_conference', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Conference $c) => ['id' => $c->id, 'name' => $c->name])
                ->all()
        );
    }

    private function conferenceId(): ?int
    {
        return $this->conference === '' ? null : (int) $this->conference;
    }

    #[Computed]
    public function games()
    {
        $season = Season::where('year', $this->year)->where('type', Season::REGULAR)->first();

        if ($season === null) {
            return collect();
        }

        $query = Game::query()
            ->with(['homeTeam:id,display_name,logo,logo_dark', 'awayTeam:id,display_name,logo,logo_dark', 'venue:id,name'])
            ->where('season_id', $season->id)
            ->when($this->week, fn ($q) => $q->whereHas('week', fn ($w) => $w->where('number', $this->week)))
            ->orderBy('kickoff_at');

        if ($this->conferenceId()) {
            // A conference's games are those involving its members that season.
            $members = TeamSeason::where('season_year', $this->year)
                ->where('conference_id', $this->conferenceId())
                ->pluck('team_id');

            $query->where(fn ($q) => $q->whereIn('home_team_id', $members)->orWhereIn('away_team_id', $members));
        }

        return $query->get()->groupBy(
            fn (Game $game) => $game->kickoff_at->setTimezone(config('cfb.timezone'))->format('l, M j')
        );
    }

    /**
     * Drives the poll interval. Only polls while something is actually live,
     * so an idle scoreboard costs nothing.
     */
    #[Computed]
    public function hasLiveGames(): bool
    {
        return Game::query()->inProgress()->exists();
    }
}; ?>

<div>
    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between gap-3">
            <flux:heading size="xl">Scoreboard</flux:heading>

            @if ($this->hasLiveGames)
                <flux:badge color="red" size="sm" class="shrink-0">Live</flux:badge>
            @endif
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:select wire:model.live="year" size="sm" class="w-28">
                @foreach ($this->seasons as $season)
                    <flux:select.option :value="$season">{{ $season }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="week" size="sm" class="w-32">
                @foreach ($this->weeks as $w)
                    <flux:select.option :value="$w['number']">{{ $w['name'] }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="conference" size="sm" class="min-w-40 flex-1">
                <flux:select.option value="">All conferences</flux:select.option>
                @foreach ($this->conferences as $c)
                    <flux:select.option :value="$c['id']">{{ $c['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        {{-- Short-polls our own cache, never ESPN, and only while a game is
             actually in progress. --}}
        <div @if ($this->hasLiveGames) wire:poll.30s.visible @endif class="flex flex-col gap-5">
            @forelse ($this->games as $day => $games)
                <div class="flex flex-col gap-2">
                    <flux:subheading class="sticky top-14 z-10 bg-white/90 py-1 backdrop-blur dark:bg-zinc-950/90">
                        {{ $day }}
                    </flux:subheading>

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($games as $game)
                            <x-game-card :game="$game" wire:key="game-{{ $game->id }}" />
                        @endforeach
                    </div>
                </div>
            @empty
                <flux:callout icon="calendar-days">
                    <flux:callout.heading>Nothing on the slate</flux:callout.heading>
                    <flux:callout.text>No games found for this week. Try another week or season.</flux:callout.text>
                </flux:callout>
            @endforelse
        </div>
    </div>
</div>
