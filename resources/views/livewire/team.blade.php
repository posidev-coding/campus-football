<?php

use App\Models\AthleteTeamSeason;
use App\Models\Game;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamLeader;
use App\Models\TeamSeasonStat;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A team's whole season on one page: record, leaders, stats, schedule, roster.
 *
 * The team's own colour drives the page accent via the --team-accent custom
 * property, so this reads as *that team's* page rather than a generic template.
 */
new class extends Component
{
    public Team $team;

    #[Url]
    public ?int $year = null;

    #[Url]
    public string $tab = 'overview';

    public function mount(Team $team): void
    {
        $this->team = $team;
        $this->year ??= config('cfb.season');
    }

    #[Computed]
    public function seasonRow(): ?\App\Models\TeamSeason
    {
        return $this->team->seasonFor($this->year)->with('conference')->first();
    }

    #[Computed]
    public function standing(): ?Standing
    {
        return Standing::fromEspn()
            ->where('season_year', $this->year)
            ->where('team_id', $this->team->id)
            ->first();
    }

    /**
     * Leaders, one row per category, in the order the team page presents them.
     *
     * Read straight from `team_leaders` rather than aggregated from athlete
     * stats — one indexed read instead of a fan-out.
     */
    #[Computed]
    public function leaders()
    {
        $rows = TeamLeader::with('athlete:id,display_name,headshot_url,slug')
            ->where('team_id', $this->team->id)
            ->where('season_year', $this->year)
            ->where('rank', 1)
            ->get()
            ->keyBy('category');

        return collect(TeamLeader::CATEGORIES)
            ->map(fn (string $c) => $rows->get($c))
            ->filter()
            ->values();
    }

    #[Computed]
    public function stats()
    {
        return TeamSeasonStat::where('team_id', $this->team->id)
            ->where('season_year', $this->year)
            ->get()
            ->keyBy('category');
    }

    #[Computed]
    public function schedule()
    {
        $seasonIds = Season::where('year', $this->year)->pluck('id');

        return Game::query()
            ->with(['homeTeam:id,display_name,abbreviation,logo', 'awayTeam:id,display_name,abbreviation,logo'])
            ->whereIn('season_id', $seasonIds)
            ->where(fn ($q) => $q->where('home_team_id', $this->team->id)->orWhere('away_team_id', $this->team->id))
            ->orderBy('kickoff_at')
            ->get();
    }

    /**
     * Roster grouped by ESPN's position groups, in depth-chart order.
     */
    #[Computed]
    public function roster()
    {
        return AthleteTeamSeason::with(['athlete:id,display_name,headshot_url,display_height,display_weight,birth_city,birth_state,slug', 'position:id,abbreviation,name'])
            ->where('team_id', $this->team->id)
            ->where('season_year', $this->rosterYear)
            ->get()
            ->sortBy(fn (AthleteTeamSeason $r) => [$r->position?->abbreviation ?? 'ZZ', $r->athlete?->display_name])
            ->groupBy('position_group');
    }

    /**
     * Which season's roster we can actually show.
     *
     * ESPN publishes only the CURRENT roster — asking for a past season returns
     * zero athletes. Past seasons therefore hold just the handful of players
     * resolved as statistical leaders, which is a worse answer than showing the
     * real roster and saying which year it is. So fall back to the most recent
     * roster we have, and label it.
     */
    #[Computed]
    public function rosterYear(): int
    {
        $exact = AthleteTeamSeason::where('team_id', $this->team->id)
            ->where('season_year', $this->year)
            ->count();

        // A season with only a few entries is leader backfill, not a roster.
        if ($exact >= 30) {
            return $this->year;
        }

        return (int) (AthleteTeamSeason::where('team_id', $this->team->id)->max('season_year') ?? $this->year);
    }

    public function groupLabel(string $group): string
    {
        return match ($group) {
            'offense' => 'Offense',
            'defense' => 'Defense',
            'special_teams' => 'Special Teams',
            'injured_reserve' => 'Injured / Out',
            'suspended' => 'Suspended',
            'practice_squad' => 'Practice Squad',
            default => str($group)->headline()->toString(),
        };
    }
}; ?>

<div
    class="flex flex-col gap-5"
    @style([
        '--team-accent: '.$team->accentColor() => $team->accentColor(),
        '--team-accent-contrast: white' => $team->accentColor(),
    ])
>
    {{-- Team hero, in the team's own colour --}}
    <div class="team-accent -mx-4 -mt-5 flex items-center gap-3 px-4 py-5">
        @if ($team->logo)
            <img src="{{ $team->logo }}" alt="" class="size-14 shrink-0 object-contain drop-shadow">
        @endif

        <div class="flex min-w-0 flex-col">
            <span class="truncate text-xl font-bold leading-tight">{{ $team->display_name }}</span>
            <span class="text-sm opacity-80">
                {{ $this->seasonRow?->conference?->name ?? 'Independent' }}
                @if ($this->standing)
                    &middot; {{ $this->standing->overallRecord() }} ({{ $this->standing->conferenceRecord() }})
                @endif
            </span>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <flux:select wire:model.live="year" size="sm" class="w-28">
            @foreach (range(config('cfb.season'), config('cfb.season') - 4) as $y)
                <flux:select.option :value="$y">{{ $y }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:radio.group wire:model.live="tab" variant="segmented" size="sm">
            <flux:radio value="overview" label="Overview" />
            <flux:radio value="schedule" label="Schedule" />
            <flux:radio value="roster" label="Roster" />
            <flux:radio value="stats" label="Stats" />
        </flux:radio.group>
    </div>

    @if ($tab === 'overview')
        <div class="flex flex-col gap-3">
            <flux:subheading>Season leaders</flux:subheading>

            @forelse ($this->leaders as $leader)
                <div class="flex items-center gap-3 rounded-lg border border-zinc-200 p-2.5 dark:border-zinc-800">
                    @if ($leader->athlete?->headshot_url)
                        <img src="{{ $leader->athlete->headshot_url }}" alt="" loading="lazy"
                             class="size-10 shrink-0 rounded-full bg-zinc-100 object-cover dark:bg-zinc-800">
                    @endif

                    <div class="flex min-w-0 flex-1 flex-col">
                        <span class="text-micro uppercase tracking-wide text-zinc-500">
                            {{ \App\Models\TeamLeader::label($leader->category) }}
                        </span>
                        <a href="{{ route('player', $leader->athlete) }}" wire:navigate
                           class="truncate text-sm font-medium hover:underline">
                            {{ $leader->athlete?->display_name }}
                        </a>
                    </div>

                    <span class="tabular shrink-0 text-right text-stat text-zinc-600 dark:text-zinc-300">
                        {{ $leader->display_value }}
                    </span>
                </div>
            @empty
                <flux:callout icon="chart-bar">
                    <flux:callout.heading>No leaders yet</flux:callout.heading>
                    <flux:callout.text>Nothing published for {{ $year }}.</flux:callout.text>
                </flux:callout>
            @endforelse
        </div>
    @endif

    @if ($tab === 'schedule')
        <div class="flex flex-col gap-2">
            @forelse ($this->schedule as $game)
                <x-game-card :game="$game" wire:key="g-{{ $game->id }}" />
            @empty
                <flux:callout icon="calendar-days">
                    <flux:callout.heading>No schedule</flux:callout.heading>
                    <flux:callout.text>Nothing on the books for {{ $year }}.</flux:callout.text>
                </flux:callout>
            @endforelse
        </div>
    @endif

    @if ($tab === 'roster')
        @if ($this->rosterYear !== $year)
            <flux:callout icon="information-circle" variant="secondary">
                <flux:callout.text>
                    ESPN publishes only the current roster, so this shows {{ $this->rosterYear }}.
                </flux:callout.text>
            </flux:callout>
        @endif

        @forelse ($this->roster as $group => $players)
            <div class="flex flex-col gap-2">
                <flux:subheading>{{ $this->groupLabel($group) }} <span class="text-zinc-400">({{ $players->count() }})</span></flux:subheading>

                <div class="flex flex-col divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800/60 dark:border-zinc-800">
                    @foreach ($players as $row)
                        <a href="{{ route('player', $row->athlete) }}" wire:navigate
                           class="flex items-center gap-3 p-2.5 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-900">
                            <span class="tabular w-7 shrink-0 text-right text-stat text-zinc-400">{{ $row->jersey ?? '—' }}</span>

                            @if ($row->athlete?->headshot_url)
                                <img src="{{ $row->athlete->headshot_url }}" alt="" loading="lazy"
                                     class="size-9 shrink-0 rounded-full bg-zinc-100 object-cover dark:bg-zinc-800">
                            @else
                                <div class="size-9 shrink-0 rounded-full bg-zinc-100 dark:bg-zinc-800"></div>
                            @endif

                            <div class="flex min-w-0 flex-1 flex-col">
                                <span class="truncate text-sm font-medium">{{ $row->athlete?->display_name }}</span>
                                <span class="truncate text-micro text-zinc-500">
                                    {{ collect([$row->position?->abbreviation, $row->experience_class, $row->athlete?->hometown()])->filter()->implode(' · ') }}
                                </span>
                            </div>

                            <span class="shrink-0 text-micro text-zinc-400">
                                {{ collect([$row->athlete?->display_height, $row->athlete?->display_weight])->filter()->implode(', ') }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        @empty
            <flux:callout icon="user-group">
                <flux:callout.heading>No roster</flux:callout.heading>
                <flux:callout.text>Nothing published for this team yet.</flux:callout.text>
            </flux:callout>
        @endforelse
    @endif

    @if ($tab === 'stats')
        @forelse ($this->stats as $category => $row)
            <div class="flex flex-col gap-2">
                <flux:subheading>{{ str($category)->headline() }}</flux:subheading>

                <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800">
                    <table class="w-full text-stat">
                        <tbody>
                            @foreach ($row->stats as $name => $value)
                                <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60">
                                    <td class="px-3 py-1.5 text-zinc-500">{{ str($name)->headline() }}</td>
                                    <td class="px-3 py-1.5 text-right font-medium">{{ $value }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <flux:callout icon="chart-bar">
                <flux:callout.heading>No statistics</flux:callout.heading>
                <flux:callout.text>Nothing published for {{ $year }}.</flux:callout.text>
            </flux:callout>
        @endforelse
    @endif
</div>
