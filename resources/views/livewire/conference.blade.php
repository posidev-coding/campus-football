<?php

use App\Models\Article;
use App\Models\Conference;
use App\Models\Game;
use App\Models\NationalLeader;
use App\Models\Season;
use App\Models\Standing;
use App\Models\TeamSeason;
use App\Services\CfbCalendar;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A conference.
 *
 * Until now every conference link in the app deep-linked to a filtered
 * standings page, which is the weakest link target in the UI — it answers one
 * question and drops the reader on a screen about something else.
 *
 * Membership is read through team_seasons for the chosen year, so this shows
 * who was actually in the conference that season rather than who is now.
 */
new class extends Component
{
    public Conference $conference;

    #[Url]
    public ?int $year = null;

    public function mount(Conference $conference, CfbCalendar $calendar): void
    {
        $this->conference = $conference;
        $this->year ??= $calendar->resultsYear();
    }

    /** @return list<int> */
    #[Computed]
    public function years(): array
    {
        return TeamSeason::where('conference_id', $this->conference->id)
            ->distinct()
            ->orderByDesc('season_year')
            ->pluck('season_year')
            ->all();
    }

    #[Computed]
    public function standings()
    {
        return Standing::query()
            ->fromEspn()
            ->with('team:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark')
            ->where('season_year', $this->year)
            ->where('conference_id', $this->conference->id)
            ->inStandingsOrder()
            ->get();
    }

    /** @return list<int> */
    private function memberIds(): array
    {
        return TeamSeason::where('season_year', $this->year)
            ->where('conference_id', $this->conference->id)
            ->pluck('team_id')
            ->all();
    }

    /**
     * The most recent slate involving this conference's teams.
     */
    #[Computed]
    public function games()
    {
        $members = $this->memberIds();

        if ($members === []) {
            return collect();
        }

        $seasonIds = Season::where('year', $this->year)->pluck('id');

        return Game::query()
            ->with([
                'homeTeam:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark',
                'awayTeam:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark',
                'venue:id,name',
                'odds',
            ])
            ->whereIn('season_id', $seasonIds)
            ->where('conference_game', true)
            ->where(fn ($q) => $q->whereIn('home_team_id', $members)->orWhereIn('away_team_id', $members))
            ->orderByDesc('kickoff_at')
            ->limit(6)
            ->get();
    }

    /**
     * Conference players on the national leaderboards.
     */
    #[Computed]
    public function leaders()
    {
        $members = $this->memberIds();

        if ($members === []) {
            return collect();
        }

        return NationalLeader::query()
            ->with([
                'athlete:id,slug,display_name,headshot_url',
                'team:id,slug,short_display_name,abbreviation,logo,logo_dark',
            ])
            ->where('season_year', $this->year)
            ->where('season_type', Season::REGULAR)
            ->whereIn('team_id', $members)
            ->whereIn('category', ['passingYards', 'rushingYards', 'receivingYards', 'totalTackles', 'sacks'])
            ->orderBy('rank')
            ->get()
            ->groupBy('category')
            ->map(fn ($rows) => $rows->take(3));
    }

    #[Computed]
    public function news()
    {
        $members = $this->memberIds();

        if ($members === []) {
            return collect();
        }

        return Article::query()
            ->with('teams:id,slug,short_display_name,abbreviation,logo,logo_dark')
            ->whereHas('teams', fn ($q) => $q->whereIn('teams.id', $members))
            ->newest()
            ->limit(5)
            ->get();
    }
}; ?>

<div class="flex flex-col gap-5">
    <div class="flex items-start justify-between gap-3">
        <div class="flex items-center gap-3">
            @if ($conference->logo)
                <img src="{{ $conference->logo }}" alt="" class="size-10 shrink-0 object-contain">
            @endif

            <div class="flex min-w-0 flex-col">
                <flux:heading size="xl" class="truncate">{{ $conference->name }}</flux:heading>
                <p class="text-stat text-zinc-500">{{ $year }} season</p>
            </div>
        </div>

        <flux:select wire:model.live="year" size="sm" class="w-24 shrink-0">
            @foreach ($this->years as $y)
                <flux:select.option :value="$y">{{ $y }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="flex flex-col gap-2">
        <flux:subheading>Standings</flux:subheading>

        <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800">
            <table class="w-full min-w-md text-stat">
                <thead>
                    <tr class="border-b border-zinc-200 text-micro uppercase tracking-wide text-zinc-500 dark:border-zinc-800">
                        <th class="px-3 py-2 text-left font-medium">Team</th>
                        <th class="px-2 py-2 text-right font-medium">Conf</th>
                        <th class="px-2 py-2 text-right font-medium">Overall</th>
                        <th class="px-3 py-2 text-right font-medium">Strk</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->standings as $row)
                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60"
                            wire:key="standing-{{ $row->team_id }}">
                            <td class="px-3 py-2"><x-team-link :team="$row->team" /></td>
                            <td class="tabular px-2 py-2 text-right font-semibold">{{ $row->conferenceRecord() }}</td>
                            <td class="tabular px-2 py-2 text-right text-zinc-500">{{ $row->overallRecord() }}</td>
                            <td class="px-3 py-2 text-right">
                                @if ($row->streak)
                                    <span class="{{ str_starts_with($row->streak, 'W') ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                        {{ $row->streak }}
                                    </span>
                                @else
                                    <span class="text-zinc-400">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-3 py-4 text-center text-zinc-500">No standings for {{ $year }}.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($this->games->isNotEmpty())
        <div class="flex flex-col gap-2">
            <flux:subheading>Recent conference games</flux:subheading>

            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($this->games as $game)
                    <x-game-card :game="$game" wire:key="cgame-{{ $game->id }}" />
                @endforeach
            </div>
        </div>
    @endif

    @if ($this->leaders->isNotEmpty())
        <div class="flex flex-col gap-2">
            <flux:subheading>National leaders from the {{ $conference->short_name ?: $conference->name }}</flux:subheading>

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($this->leaders as $category => $rows)
                    <div class="flex flex-col gap-1 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800"
                         wire:key="cat-{{ $category }}">
                        <p class="text-micro font-semibold uppercase tracking-wide text-zinc-500">
                            {{ str($category)->headline() }}
                        </p>

                        @foreach ($rows as $leader)
                            <div class="flex items-center gap-2">
                                <span class="tabular w-6 shrink-0 text-right text-micro text-zinc-400">{{ $leader->rank }}</span>
                                <div class="min-w-0 flex-1">
                                    @if ($leader->athlete)
                                        <x-player-link :athlete="$leader->athlete" size="xs" />
                                    @else
                                        <span class="text-micro text-zinc-500">Unidentified</span>
                                    @endif
                                </div>
                                <span class="tabular shrink-0 text-stat font-semibold">{{ $leader->display_value }}</span>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($this->news->isNotEmpty())
        <div class="flex flex-col gap-2">
            <flux:subheading>News</flux:subheading>

            @foreach ($this->news as $article)
                <x-article-card :article="$article" compact wire:key="cnews-{{ $article->id }}" />
            @endforeach
        </div>
    @endif
</div>
