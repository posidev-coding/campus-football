<?php

use App\Models\AthleteGameStat;
use App\Models\Game;
use App\Services\Espn\Sync\SyncGameSummary;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A single game: box score, scoring summary, drives, win probability.
 *
 * This is the ONLY screen in the app that can cause an ESPN request, and it
 * does so under tight constraints. The box score exists nowhere except the
 * `summary` payload, which is 544 KB — larger than a whole day's scoreboard —
 * so:
 *
 *   - A FINAL game is fetched once, ever. Its summary cannot change, so every
 *     later visit is a pure database read and costs nothing upstream.
 *   - A LIVE game is fetched at most once a minute, throttled on the GAME
 *     rather than on the viewer. A hundred people watching one game is one
 *     request a minute — the same invariant the scoreboard holds.
 *
 * The throttle lives in SyncGameSummary::refresh(), which is what makes it
 * shared across every viewer rather than per-session.
 */
new class extends Component
{
    public Game $game;

    #[Url]
    public string $tab = 'box';

    public function mount(Game $game): void
    {
        $this->game = $game->load([
            'homeTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark,color',
            'awayTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark,color',
            'venue',
            'week:id,name',
            'season:id,year',
        ]);

        $this->hydrateSummary();
    }

    /**
     * Pull the summary if we do not have it, or if the game is live and the
     * stored copy has gone stale.
     */
    private function hydrateSummary(): void
    {
        app(SyncGameSummary::class)->refresh($this->game);
    }

    /**
     * Live games re-poll. Polling calls `refresh()` again, which is throttled,
     * so the extra viewers cost database reads rather than ESPN requests.
     */
    public function poll(): void
    {
        $this->game->refresh();
        $this->hydrateSummary();

        unset($this->teamStats, $this->scoringPlays, $this->playerStats, $this->summary);
    }

    #[Computed]
    public function isLive(): bool
    {
        return $this->game->status === 'in';
    }

    #[Computed]
    public function summary()
    {
        return $this->game->summary()->first();
    }

    /** Keyed by team id so the two sides can be laid out against each other. */
    #[Computed]
    public function teamStats()
    {
        return $this->game->teamStats()->get()->keyBy('team_id');
    }

    #[Computed]
    public function scoringPlays()
    {
        return $this->game->scoringPlays()
            ->with('team:id,slug,abbreviation,short_display_name,logo,logo_dark')
            ->inOrder()
            ->get();
    }

    /**
     * Player box score, grouped by team then category.
     *
     * @return \Illuminate\Support\Collection
     */
    #[Computed]
    public function playerStats()
    {
        return AthleteGameStat::query()
            ->with('athlete:id,slug,display_name,short_name,headshot_url')
            ->where('game_id', $this->game->id)
            ->get()
            ->groupBy('team_id')
            ->map(fn ($rows) => $rows->groupBy('category'));
    }

    /**
     * The two sides, away first — the order a scoreboard is read in.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function sides(): array
    {
        return [
            ['team' => $this->game->awayTeam, 'score' => $this->game->away_score, 'rank' => $this->game->away_rank, 'record' => $this->game->away_record, 'line' => $this->game->away_line_scores],
            ['team' => $this->game->homeTeam, 'score' => $this->game->home_score, 'rank' => $this->game->home_rank, 'record' => $this->game->home_record, 'line' => $this->game->home_line_scores],
        ];
    }

    /** @return list<string> */
    #[Computed]
    public function tabs(): array
    {
        $tabs = ['box' => 'Box Score'];

        if ($this->scoringPlays->isNotEmpty()) {
            $tabs['scoring'] = 'Scoring';
        }

        $tabs['odds'] = 'Odds';

        return $tabs;
    }
}; ?>

<div class="flex flex-col gap-4" @if ($this->isLive) wire:poll.30s.visible="poll" @endif>
    {{-- Header: the matchup, the score, and where it is being played. --}}
    <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-center justify-between gap-2 text-micro text-zinc-500">
            <span class="truncate">
                {{ $game->week?->name }}
                @if ($game->venue)
                    · {{ $game->venue->name }}@if ($game->venue->city), {{ $game->venue->city }}@endif
                @endif
            </span>

            @if ($this->isLive)
                <span class="flex shrink-0 items-center gap-1 font-semibold text-red-600 dark:text-red-400">
                    <span class="size-1.5 animate-pulse rounded-full bg-current"></span>
                    {{ $game->status_detail ?? 'Live' }}
                </span>
            @elseif ($game->completed)
                <span class="shrink-0 font-medium">Final</span>
            @else
                <span class="shrink-0 font-medium">
                    {{ $game->kickoff_at->setTimezone(config('cfb.timezone'))->format('D, M j · g:ia') }}
                </span>
            @endif
        </div>

        @foreach ($this->sides as $side)
            @php
                $winner = $game->winnerTeamId();
                $lost = $game->completed && $winner !== null && $winner !== $side['team']?->id;
            @endphp

            <div class="flex items-center gap-3">
                <x-team-link
                    :team="$side['team']"
                    :rank="$side['rank']"
                    :record="$side['record']"
                    :muted="$lost"
                    size="md"
                    class="min-w-0 flex-1"
                />

                {{-- Quarter-by-quarter, which the scoreboard feed already gives
                     us — so this renders for a live game with no summary. --}}
                @if ($side['line'])
                    <div class="hidden items-center gap-2 sm:flex">
                        @foreach ($side['line'] as $quarter)
                            <span class="tabular w-5 text-center text-stat text-zinc-400">{{ $quarter }}</span>
                        @endforeach
                    </div>
                @endif

                <span @class([
                    'tabular w-10 shrink-0 text-right text-xl tracking-tight',
                    'font-bold' => ! $lost,
                    'font-semibold text-zinc-400' => $lost,
                ])>
                    {{ $game->completed || $this->isLive ? $side['score'] : '—' }}
                </span>
            </div>
        @endforeach

        @if ($this->summary?->attendance)
            <p class="text-micro text-zinc-500">
                Attendance {{ number_format($this->summary->attendance) }}
            </p>
        @endif
    </div>

    {{-- Nothing to show yet: an upcoming game has no box score, and saying so
         is better than an empty tab strip. --}}
    @if (! $game->completed && ! $this->isLive)
        <x-odds-strip :game="$game" class="text-sm" />

        <flux:callout icon="clock">
            <flux:callout.heading>Not played yet</flux:callout.heading>
            <flux:callout.text>
                Box score, scoring summary and drives appear once this one kicks off.
            </flux:callout.text>
        </flux:callout>
    @else
        <flux:tabs wire:model.live="tab">
            @foreach ($this->tabs as $value => $label)
                <flux:tab :name="$value">{{ $label }}</flux:tab>
            @endforeach
        </flux:tabs>

        @if ($tab === 'box')
            @include('partials.game-box-score')
        @elseif ($tab === 'scoring')
            @include('partials.game-scoring')
        @elseif ($tab === 'odds')
            <div class="flex flex-col gap-3">
                <x-odds-strip :game="$game" class="text-sm" />

                @if ($game->predictor)
                    <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800">
                        <table class="w-full text-stat">
                            <tbody>
                                @if ($game->predictor->matchup_quality !== null)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800/60">
                                        <td class="px-3 py-1.5 text-zinc-500">Matchup quality</td>
                                        <td class="px-3 py-1.5 text-right font-medium">{{ $game->predictor->matchup_quality }}</td>
                                    </tr>
                                @endif
                                @if ($game->predictor->game_quality !== null)
                                    <tr>
                                        <td class="px-3 py-1.5 text-zinc-500">Game quality</td>
                                        <td class="px-3 py-1.5 text-right font-medium">{{ $game->predictor->game_quality }}</td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
