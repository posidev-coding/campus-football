@props(['game', 'predictor'])

{{--
    The matchup predictor — ESPN's donut, drawn by hand. One SVG ring split
    by gameProjection, each arc in its side of the chart pair (set as CSS
    custom properties by the wrapper; neutral in dark via chart-pair). No
    charting library: two circles with stroke-dasharray IS the chart.

    Percentages and abbreviations flank the ring, so the numbers never depend
    on the colors.
--}}
@php
    $away = (float) $predictor->away_projection;
    $home = (float) $predictor->home_projection;

    $circumference = 2 * M_PI * 45;
    $stroke = 11;

    /*
     * BOTH arcs begin at top dead centre and sweep away from each other —
     * home clockwise down the right, away mirrored down the left — so each
     * team's color sits under its own logo and the split is always at twelve
     * o'clock regardless of the numbers.
     *
     * Two earlier shapes were wrong in instructive ways. Drawing away first,
     * clockwise, put its color on the RIGHT under the home logo: each side
     * reading as the other team, on the component whose entire job is saying
     * who is favored. Starting the second arc where the first ended then fixed
     * the colors but let the origin wander with the split — a 20/80 game began
     * its ring a fifth of the way round.
     *
     * The mirror is `translate(120,0) scale(-1,1)`, which reflects about the
     * vertical centre line: a clockwise-from-top arc becomes a
     * counter-clockwise-from-top one, still centred on the same circle.
     */
    $gap = 7;

    /*
     * Round caps EXTEND a dash by half the stroke width at each end, so the
     * offset that produces a visible gap of $gap between two neighbouring
     * ends is half the gap PLUS half the stroke. Applied at both the start
     * (the twelve o'clock split) and the end (where they meet at the bottom),
     * which is why each arc loses twice it.
     */
    $endInset = ($gap / 2) + ($stroke / 2);
    $startAngle = -90 + ($endInset / $circumference) * 360;

    $awayArc = max(0, $circumference * ($away / 100) - 2 * $endInset);
    $homeArc = max(0, $circumference * ($home / 100) - 2 * $endInset);

    $margin = $predictor->home_pred_pt_diff !== null && $predictor->away_pred_pt_diff !== null
        ? ($predictor->home_pred_pt_diff >= $predictor->away_pred_pt_diff
            ? ['team' => $game->homeTeam, 'by' => $predictor->home_pred_pt_diff]
            : ['team' => $game->awayTeam, 'by' => $predictor->away_pred_pt_diff])
        : null;
@endphp

<div class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
    <h3 class="text-micro font-semibold tracking-wide text-zinc-400 uppercase">Matchup predictor</h3>

    <div class="flex items-center justify-center gap-4">
        <div class="flex flex-col items-center gap-1">
            <x-team-logo :team="$game->awayTeam" size="md" />
            <span class="text-micro font-medium text-zinc-500">{{ $game->awayTeam?->abbreviation ?? 'TBD' }}</span>
            <span class="tabular text-lg font-bold" style="color: var(--chart-away)">{{ rtrim(rtrim(number_format($away, 1), '0'), '.') }}%</span>
        </div>

        <svg viewBox="0 0 120 120" class="size-32 shrink-0" role="img"
             aria-label="Win projection: {{ $game->awayTeam?->abbreviation }} {{ $away }}%, {{ $game->homeTeam?->abbreviation }} {{ $home }}%">
            {{-- Home: clockwise from top, down the right.

                 Drawn STATIC, deliberately. Two entrance animations were tried
                 and both could render an empty ring: an Alpine flag flipped
                 from requestAnimationFrame, and a CSS keyframe from a zero
                 dasharray. Measured in a real browser, the animation reported
                 playState "running" with currentTime frozen at 0 — so the arcs
                 held their from-state indefinitely and the card showed nothing
                 at all. A flourish whose stalled state hides the content is
                 load-bearing, and this component's job is saying who is
                 favored. --}}
            <circle cx="60" cy="60" r="45" fill="none" stroke-width="{{ $stroke }}" stroke-linecap="round"
                    transform="rotate({{ $startAngle }} 60 60)"
                    style="stroke: var(--chart-home)"
                    stroke-dasharray="{{ $homeArc }} {{ $circumference }}" />

            {{-- Away: the same arc mirrored, so it leaves the same point going
                 the other way and runs down the left. --}}
            <circle cx="60" cy="60" r="45" fill="none" stroke-width="{{ $stroke }}" stroke-linecap="round"
                    transform="translate(120, 0) scale(-1, 1) rotate({{ $startAngle }} 60 60)"
                    style="stroke: var(--chart-away)"
                    stroke-dasharray="{{ $awayArc }} {{ $circumference }}" />
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
