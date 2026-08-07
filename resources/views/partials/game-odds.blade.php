{{--
    The odds block: the current line, then how it MOVED — open against current
    per book. Movement is our own observation history: ESPN's true opening
    line is not retrievable, so the first line we saw is frozen as `open`
    and everything after accumulates from there. An older game may only ever
    have one row, and the table renders honestly with whatever exists.

    Rendered two ways. As its own TAB once a game is under way ($standalone),
    where it carries the predictor's quality figures and its own empty state.
    Folded into the top of the PREVIEW before kickoff, where the matchup donut
    two cards below already prints matchup quality and the preview owns the
    empty state — printing either again would say the same thing twice on one
    scroll.
--}}
@php
    $standalone ??= true;

    $byProvider = $game->odds()->orderBy('provider_id')->get()->groupBy('provider');
@endphp

{{-- Folded into the preview, an unposted game contributes NOTHING rather than
     an empty wrapper: the parent is a flex column with a gap, so a childless
     div is still a visible hole between the scorebug and the donut. --}}
@if ($standalone || $byProvider->isNotEmpty())
<div class="flex flex-col gap-3">
    <x-odds-strip :game="$game" class="text-sm" />

    @foreach ($byProvider as $provider => $rows)
        @php
            $open = $rows->firstWhere('phase', 'open');
            $current = $rows->firstWhere('phase', 'current');
            $moved = $open !== null && $current !== null && $open->spread !== null && $current->spread !== null
                ? round($current->spread - $open->spread, 1)
                : null;
        @endphp

        <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800" wire:key="odds-{{ $provider }}">
            <table class="w-full text-stat">
                <thead>
                    <tr class="border-b border-zinc-100 text-micro text-zinc-400 dark:border-zinc-800/60">
                        <th class="px-3 py-1.5 text-left font-medium">{{ $provider }}</th>
                        <th class="px-3 py-1.5 text-right font-medium">Spread</th>
                        <th class="px-3 py-1.5 text-right font-medium">Total</th>
                        <th class="px-3 py-1.5 text-right font-medium">ML</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (['open' => $open, 'current' => $current] as $phase => $row)
                        @continue($row === null)

                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60" wire:key="odds-{{ $provider }}-{{ $phase }}">
                            <td class="px-3 py-1.5 text-zinc-500">
                                {{ ucfirst($phase) }}
                                @if ($row->captured_at)
                                    <span class="text-micro text-zinc-400">{{ $row->captured_at->setTimezone(config('cfb.timezone'))->format('M j') }}</span>
                                @endif
                            </td>
                            <td class="tabular px-3 py-1.5 text-right font-medium">{{ $row->details ?: $row->spread ?? '—' }}</td>
                            <td class="tabular px-3 py-1.5 text-right">{{ $row->over_under !== null ? rtrim(rtrim(number_format($row->over_under, 1), '0'), '.') : '—' }}</td>
                            <td class="tabular px-3 py-1.5 text-right">
                                @if ($row->moneyline_home !== null)
                                    {{ $game->homeTeam?->abbreviation }} {{ $row->moneyline_home > 0 ? '+' : '' }}{{ $row->moneyline_home }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach

                    @if ($moved !== null && $moved != 0)
                        <tr>
                            <td colspan="4" class="px-3 py-1.5 text-micro text-zinc-500">
                                Line has moved
                                <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $moved > 0 ? '+' : '' }}{{ $moved }}</span>
                                since our first observation — the money proxy the quality score reads.
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    @endforeach

    @if ($standalone && $game->predictor)
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

    @if ($standalone && $byProvider->isEmpty() && $game->predictor === null)
        <flux:callout icon="chart-bar">
            <flux:callout.heading>No line yet</flux:callout.heading>
            <flux:callout.text>Odds ride along once books post this game.</flux:callout.text>
        </flux:callout>
    @endif
</div>
@endif
