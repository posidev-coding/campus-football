@props(['game', 'predictor'])

{{--
    The matchup predictor — ESPN's donut, drawn by hand. One SVG ring split
    by gameProjection, each arc in its side of the chart pair (set as CSS
    custom properties by the wrapper; neutral in dark via chart-pair). No
    charting library: two circles with stroke-dasharray IS the chart.

    The arcs grow from zero on first paint — stroke-dasharray transitions in
    every browser we serve — and prefers-reduced-motion renders them final
    through motion-reduce. Percentages and abbreviations flank the ring, so
    the numbers never depend on the colors.
--}}
@php
    $away = (float) $predictor->away_projection;
    $home = (float) $predictor->home_projection;

    $circumference = 2 * M_PI * 45;
    $gap = 6;

    $awayArc = max(0, $circumference * ($away / 100) - $gap);
    $homeArc = max(0, $circumference * ($home / 100) - $gap);

    // Home starts where away's share ends; both rotate from 12 o'clock.
    $homeStart = -90 + 360 * ($away / 100);

    $margin = $predictor->home_pred_pt_diff !== null && $predictor->away_pred_pt_diff !== null
        ? ($predictor->home_pred_pt_diff >= $predictor->away_pred_pt_diff
            ? ['team' => $game->homeTeam, 'by' => $predictor->home_pred_pt_diff]
            : ['team' => $game->awayTeam, 'by' => $predictor->away_pred_pt_diff])
        : null;
@endphp

<div class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
    <h3 class="text-micro font-semibold tracking-wide text-zinc-400 uppercase">Matchup predictor</h3>

    <div class="flex items-center justify-center gap-4" x-data="{ shown: false }" x-init="requestAnimationFrame(() => shown = true)">
        <div class="flex flex-col items-center gap-1">
            <x-team-logo :team="$game->awayTeam" size="md" />
            <span class="text-micro font-medium text-zinc-500">{{ $game->awayTeam?->abbreviation ?? 'TBD' }}</span>
            <span class="tabular text-lg font-bold" style="color: var(--chart-away)">{{ rtrim(rtrim(number_format($away, 1), '0'), '.') }}%</span>
        </div>

        <svg viewBox="0 0 120 120" class="size-32 shrink-0" role="img"
             aria-label="Win projection: {{ $game->awayTeam?->abbreviation }} {{ $away }}%, {{ $game->homeTeam?->abbreviation }} {{ $home }}%">
            <circle cx="60" cy="60" r="45" fill="none" stroke-width="11"
                    class="stroke-zinc-100 dark:stroke-zinc-800" />
            <circle cx="60" cy="60" r="45" fill="none" stroke-width="11" stroke-linecap="round"
                    transform="rotate(-90 60 60)"
                    class="transition-[stroke-dasharray] duration-700 ease-out motion-reduce:transition-none"
                    style="stroke: var(--chart-away)"
                    :stroke-dasharray="shown ? '{{ $awayArc }} {{ $circumference - $awayArc }}' : '0 {{ $circumference }}'"
                    stroke-dasharray="0 {{ $circumference }}" />
            <circle cx="60" cy="60" r="45" fill="none" stroke-width="11" stroke-linecap="round"
                    transform="rotate({{ $homeStart }} 60 60)"
                    class="transition-[stroke-dasharray] duration-700 ease-out motion-reduce:transition-none"
                    style="stroke: var(--chart-home)"
                    :stroke-dasharray="shown ? '{{ $homeArc }} {{ $circumference - $homeArc }}' : '0 {{ $circumference }}'"
                    stroke-dasharray="0 {{ $circumference }}" />
        </svg>

        <div class="flex flex-col items-center gap-1">
            <x-team-logo :team="$game->homeTeam" size="md" />
            <span class="text-micro font-medium text-zinc-500">{{ $game->homeTeam?->abbreviation ?? 'TBD' }}</span>
            <span class="tabular text-lg font-bold" style="color: var(--chart-home)">{{ rtrim(rtrim(number_format($home, 1), '0'), '.') }}%</span>
        </div>
    </div>

    <div class="flex items-center justify-center gap-4 text-micro text-zinc-500">
        @if ($margin !== null && $margin['team'] !== null)
            <span>
                Projected:
                <span class="font-semibold text-zinc-700 dark:text-zinc-200">
                    {{ $margin['team']->abbreviation }} by {{ rtrim(rtrim(number_format(abs($margin['by']), 1), '0'), '.') }}
                </span>
            </span>
        @endif

        @if ($predictor->matchup_quality !== null)
            <span>
                Matchup quality:
                <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $predictor->matchup_quality }}</span>
            </span>
        @endif
    </div>
</div>
