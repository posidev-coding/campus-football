{{--
    The matchup preview — what a reader wants before kickoff: who wins, how
    the two stack up, whose players matter, and what happened last time.

    Chart marks draw in the pair TeamPalette::chartColors resolved together
    (a pale brand cannot vanish, two red teams cannot merge); dark mode
    neutralizes both through the chart-pair utility. Color is never the only
    distinguisher — every mark sits beside its team's logo or abbreviation.
--}}
<div
    class="chart-pair flex flex-col gap-4"
    style="--chart-away: {{ $this->chartColors[0] }}; --chart-home: {{ $this->chartColors[1] }}"
>
    @if ($game->predictor?->home_projection !== null)
        <x-matchup-donut :game="$game" :predictor="$game->predictor" />
    @endif

    <x-odds-strip :game="$game" class="text-sm" />

    {{-- Each side's last five, side by side. Stacked at base and two-up from
         `sm`: the additive rule — the wide layout adds a column, it is never
         the only place the second team is reachable. --}}
    @if ($this->trends !== [])
        <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
            <h3 class="text-micro font-semibold tracking-wide text-zinc-400 uppercase">Last five games</h3>

            <div class="grid gap-4 sm:grid-cols-2 sm:gap-6 sm:divide-x sm:divide-zinc-100 sm:dark:divide-zinc-800/60">
                @foreach ($this->sides as $side)
                    @continue($side['team'] === null)

                    {{-- The grid's gap and divide-x do the separating; a
                         conditional padding class here would be a Blade
                         directive inside a component attribute, which does
                         not compile. --}}
                    <x-last-five
                        :team="$side['team']"
                        :games="$this->trends[$side['team']->id] ?? collect()"
                        :class="$loop->first ? '' : 'sm:ps-6'"
                        wire:key="l5card-{{ $side['team']->id }}"
                    />
                @endforeach
            </div>
        </div>
    @endif

    {{-- Season comparison: two-sided bars in the chart pair. --}}
    @if ($this->comparison['rows'] !== [])
        <div class="flex flex-col gap-2.5 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
            <div class="flex items-baseline justify-between gap-2">
                <h3 class="text-micro font-semibold tracking-wide text-zinc-400 uppercase">Team comparison</h3>
                @if ($this->comparison['year'] !== null && $this->comparison['year'] !== $game->season?->year)
                    {{-- The season being played has no numbers yet; say whose these are. --}}
                    <span class="text-micro text-zinc-400">{{ $this->comparison['year'] }} numbers</span>
                @endif
            </div>

            @foreach ($this->comparison['rows'] as $row)
                @php
                    $away = $row['away']['value'] ?? 0;
                    $home = $row['home']['value'] ?? 0;
                    $total = max($away + $home, 0.001);
                @endphp

                <div wire:key="cmp-{{ $loop->index }}">
                    <div class="flex items-baseline justify-between text-stat">
                        <span class="tabular font-semibold">{{ $row['away']['display'] ?? '—' }}</span>
                        <span class="text-micro text-zinc-500">{{ $row['label'] }}</span>
                        <span class="tabular font-semibold">{{ $row['home']['display'] ?? '—' }}</span>
                    </div>

                    <div class="mt-1 flex h-1.5 gap-0.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                        <div class="h-full rounded-full" style="width: {{ round($away / $total * 100, 1) }}%; background-color: var(--chart-away)"></div>
                        <div class="h-full flex-1 rounded-full" style="background-color: var(--chart-home)"></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Season leaders — the probable-pitchers card of a football preview. --}}
    @if ($this->seasonLeaders['rows'] !== [])
        <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
            <div class="flex items-baseline justify-between gap-2">
                <h3 class="text-micro font-semibold tracking-wide text-zinc-400 uppercase">Players to watch</h3>
                @if ($this->seasonLeaders['year'] !== null && $this->seasonLeaders['year'] !== $game->season?->year)
                    <span class="text-micro text-zinc-400">{{ $this->seasonLeaders['year'] }} numbers</span>
                @endif
            </div>

            @foreach ($this->seasonLeaders['rows'] as $row)
                <div class="flex flex-col gap-1.5" wire:key="ldr-{{ $row['label'] }}">
                    <span class="text-center text-micro text-zinc-400">{{ $row['label'] }}</span>

                    <div class="grid grid-cols-2 gap-3">
                        @foreach (['away', 'home'] as $sideKey)
                            <div @class(['flex min-w-0 flex-col gap-0.5', 'items-end text-right' => $sideKey === 'home'])>
                                @if ($row[$sideKey] !== null && $row[$sideKey]['athlete'])
                                    <x-player-link :athlete="$row[$sideKey]['athlete']" size="xs" />
                                    <span class="tabular text-micro text-zinc-500">
                                        {{ number_format($row[$sideKey]['yards']) }} yds · {{ (int) $row[$sideKey]['tds'] }} TD
                                    </span>
                                @else
                                    <span class="text-micro text-zinc-400">—</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Last meetings, from our own games table. --}}
    @if ($this->lastMeetings->isNotEmpty())
        <div class="flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-800">
            <h3 class="border-b border-zinc-100 px-3 py-2 text-micro font-semibold tracking-wide text-zinc-400 uppercase dark:border-zinc-800/60">
                Last meetings
            </h3>

            <ol class="flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800/60">
                @foreach ($this->lastMeetings as $meeting)
                    @php
                        $winner = $meeting->winnerTeamId();
                    @endphp

                    <li wire:key="meet-{{ $meeting->id }}">
                        <a href="{{ route('game', $meeting) }}" wire:navigate class="flex items-center gap-2 px-3 py-2 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <span class="w-16 shrink-0 text-micro text-zinc-400">
                                {{ $meeting->kickoff_at->setTimezone(config('cfb.timezone'))->format('M j, Y') }}
                            </span>

                            <span class="flex min-w-0 flex-1 items-center justify-end gap-1.5 text-stat">
                                <span @class(['truncate', 'font-semibold' => $winner === $meeting->away_team_id, 'text-zinc-500' => $winner !== $meeting->away_team_id])>
                                    {{ $meeting->awayTeam?->abbreviation ?? 'TBD' }}
                                </span>
                                <x-team-logo :team="$meeting->awayTeam" size="xs" class="shrink-0" />
                                <span class="tabular font-semibold">{{ $meeting->away_score }}</span>
                            </span>

                            <span class="shrink-0 text-micro text-zinc-400">–</span>

                            <span class="flex min-w-0 flex-1 items-center gap-1.5 text-stat">
                                <span class="tabular font-semibold">{{ $meeting->home_score }}</span>
                                <x-team-logo :team="$meeting->homeTeam" size="xs" class="shrink-0" />
                                <span @class(['truncate', 'font-semibold' => $winner === $meeting->home_team_id, 'text-zinc-500' => $winner !== $meeting->home_team_id])>
                                    {{ $meeting->homeTeam?->abbreviation ?? 'TBD' }}
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    @if ($game->predictor === null && $this->comparison['rows'] === [] && $this->lastMeetings->isEmpty())
        <flux:callout icon="clock">
            <flux:callout.heading>Not played yet</flux:callout.heading>
            <flux:callout.text>
                The matchup predictor and comparison land as ESPN models the game.
            </flux:callout.text>
        </flux:callout>
    @endif
</div>
