{{--
    Team stats side by side, then the player box score per team.

    Both read their column order from `display_stats`, not from the `stats` map,
    because MySQL does not preserve JSON object key order — a passing line stored
    C/ATT, YDS, AVG, TD comes back with QBR first. The order lives in a JSON
    ARRAY alongside the map, and the values are looked up through it.
--}}
@php
    $away = $this->game->awayTeam;
    $home = $this->game->homeTeam;
    $awayStats = $this->teamStats->get($away?->id);
    $homeStats = $this->teamStats->get($home?->id);
@endphp

<div class="flex flex-col gap-5">
    @if ($awayStats || $homeStats)
        <div class="flex flex-col gap-2">
            <flux:subheading>Team Stats</flux:subheading>

            <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800">
                <table class="w-full min-w-md text-stat">
                    <thead>
                        <tr class="border-b border-zinc-200 text-micro uppercase tracking-wide text-zinc-500 dark:border-zinc-800">
                            <th class="px-3 py-2 text-left font-medium">Stat</th>
                            <th class="w-20 px-2 py-2 text-right font-medium">{{ $away?->abbreviation }}</th>
                            <th class="w-20 px-3 py-2 text-right font-medium">{{ $home?->abbreviation }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (($awayStats ?? $homeStats)->ordered() as $stat)
                            <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60">
                                <td class="px-3 py-1.5 text-zinc-500">{{ $stat['label'] }}</td>
                                <td class="tabular px-2 py-1.5 text-right font-medium">
                                    {{ $awayStats?->stats[$stat['name']] ?? '—' }}
                                </td>
                                <td class="tabular px-3 py-1.5 text-right font-medium">
                                    {{ $homeStats?->stats[$stat['name']] ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @foreach ($this->sides as $side)
        @php $categories = $this->playerStats->get($side['team']?->id); @endphp

        @if ($categories)
            <div class="flex flex-col gap-2">
                <flux:subheading class="flex items-center gap-2">
                    <x-team-link :team="$side['team']" label="short" size="sm" />
                </flux:subheading>

                @foreach ($categories as $category => $rows)
                    @php $columns = $rows->first()?->display_stats ?? []; @endphp

                    <div class="flex flex-col gap-1">
                        <p class="text-micro font-semibold uppercase tracking-wide text-zinc-500">
                            {{ str($category)->headline() }}
                        </p>

                        <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800">
                            <table class="w-full min-w-md text-stat">
                                <thead>
                                    <tr class="border-b border-zinc-200 text-micro uppercase tracking-wide text-zinc-500 dark:border-zinc-800">
                                        <th class="px-3 py-2 text-left font-medium">Player</th>
                                        @foreach ($columns as $column)
                                            <th class="px-2 py-2 text-right font-medium">{{ $column['label'] }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60"
                                            wire:key="line-{{ $row->id }}">
                                            <td class="px-3 py-1.5">
                                                <x-player-link :athlete="$row->athlete" size="xs" />
                                            </td>
                                            @foreach ($columns as $column)
                                                <td class="tabular px-2 py-1.5 text-right">
                                                    {{ $row->stats[$column['name']] ?? '—' }}
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endforeach

    @if ($this->teamStats->isEmpty() && $this->playerStats->isEmpty())
        <flux:callout icon="chart-bar">
            <flux:callout.heading>No box score</flux:callout.heading>
            <flux:callout.text>Nothing published for this game yet.</flux:callout.text>
        </flux:callout>
    @endif
</div>
