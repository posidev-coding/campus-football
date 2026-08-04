<?php

use App\Models\Game;
use App\Models\Season;
use App\Services\CfbCalendar;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bowls and the College Football Playoff.
 *
 * The postseason is its own ESPN season type (3), not a set of extra weeks on
 * the regular season — which is why it has to be queried by season type rather
 * than by week number. Its single week is named "Bowls" and is also week 1, the
 * collision that the week scroller keys on ids to avoid.
 */
new class extends Component
{
    #[Url]
    public ?int $year = null;

    public function mount(CfbCalendar $calendar): void
    {
        $this->year ??= $this->latestPostseasonYear() ?? $calendar->resultsYear();
    }

    private function latestPostseasonYear(): ?int
    {
        return Cache::remember('bowls:latest-year', 3600, fn () => Season::query()
            ->where('type', Season::POSTSEASON)
            ->whereExists(fn ($q) => $q->selectRaw(1)->from('games')->whereColumn('games.season_id', 'seasons.id'))
            ->max('year'));
    }

    /** @return list<int> */
    #[Computed]
    public function years(): array
    {
        return Cache::remember('bowls:years', 3600, fn () => Season::query()
            ->where('type', Season::POSTSEASON)
            ->whereExists(fn ($q) => $q->selectRaw(1)->from('games')->whereColumn('games.season_id', 'seasons.id'))
            ->orderByDesc('year')
            ->pluck('year')
            ->all());
    }

    /**
     * Playoff games separated from the rest of the bowl slate.
     *
     * ESPN does not flag which bowls are playoff games, so this matches on the
     * event name. Fragile by nature — the CFP has renamed its rounds more than
     * once — so a miss degrades to listing the game under Bowls rather than
     * hiding it.
     */
    #[Computed]
    public function games()
    {
        $season = Season::where('year', $this->year)->where('type', Season::POSTSEASON)->first();

        if ($season === null) {
            return collect();
        }

        return Game::query()
            ->with([
                'homeTeam:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark',
                'awayTeam:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark',
                'venue:id,name',
                'odds',
            ])
            ->where('season_id', $season->id)
            ->orderBy('kickoff_at')
            ->get()
            ->groupBy(fn (Game $g) => str_contains(strtolower($g->name ?? ''), 'playoff')
                || str_contains(strtolower($g->name ?? ''), 'national championship')
                    ? 'Playoff'
                    : 'Bowls');
    }
}; ?>

<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between gap-3">
        <flux:heading size="xl">Bowls</flux:heading>

        <flux:select wire:model.live="year" size="sm" class="w-24">
            @foreach ($this->years as $y)
                <flux:select.option :value="$y">{{ $y }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @forelse ($this->games as $group => $games)
        <div class="flex flex-col gap-2">
            <flux:subheading>{{ $group }}</flux:subheading>

            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($games as $game)
                    <x-game-card :game="$game" wire:key="bowl-{{ $game->id }}" />
                @endforeach
            </div>
        </div>
    @empty
        <flux:callout icon="trophy">
            <flux:callout.heading>No postseason games</flux:callout.heading>
            <flux:callout.text>Nothing synced for {{ $year }}.</flux:callout.text>
        </flux:callout>
    @endforelse
</div>
