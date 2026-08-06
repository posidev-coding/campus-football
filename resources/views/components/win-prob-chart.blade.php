@props(['game', 'points'])

{{--
    Win probability over the game — a hand-rolled SVG polyline, no charting
    library. `points` is the downsampled home-win series (0-100).

    The one line is drawn twice and clipped at the midline, so the stretch
    where the home side is favored renders in its chart color and the away
    stretches in theirs — and because color is never the only distinguisher,
    each half of the axis is labelled with its team's abbreviation.

    vector-effect keeps the stroke width honest under the non-uniform
    viewBox scaling.
--}}
@php
    $count = count($points);

    $coords = collect($points)
        ->map(fn (float $value, int $index) => sprintf(
            '%.2f,%.2f',
            $count > 1 ? $index / ($count - 1) * 100 : 0,
            40 - ($value / 100 * 40),
        ))
        ->implode(' ');

    $clipId = 'wp-'.$game->id;
@endphp

@if ($count > 1)
    <div class="flex flex-col gap-1 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
        <div class="flex items-baseline justify-between">
            <h3 class="text-micro font-semibold tracking-wide text-zinc-400 uppercase">Win probability</h3>
            <span class="text-micro text-zinc-400">{{ $game->homeTeam?->abbreviation }} ↑ · {{ $game->awayTeam?->abbreviation }} ↓</span>
        </div>

        <svg viewBox="0 0 100 40" preserveAspectRatio="none" class="h-24 w-full" role="img"
             aria-label="Win probability through the game">
            <defs>
                <clipPath id="{{ $clipId }}-home"><rect x="0" y="0" width="100" height="20" /></clipPath>
                <clipPath id="{{ $clipId }}-away"><rect x="0" y="20" width="100" height="20" /></clipPath>
            </defs>

            {{-- The 50/50 midline. --}}
            <line x1="0" y1="20" x2="100" y2="20" stroke-dasharray="2 2" vector-effect="non-scaling-stroke"
                  class="stroke-zinc-200 dark:stroke-zinc-700" stroke-width="1" />

            <polyline points="{{ $coords }}" fill="none" stroke-width="1.5" vector-effect="non-scaling-stroke"
                      clip-path="url(#{{ $clipId }}-home)" style="stroke: var(--chart-home)" />
            <polyline points="{{ $coords }}" fill="none" stroke-width="1.5" vector-effect="non-scaling-stroke"
                      clip-path="url(#{{ $clipId }}-away)" style="stroke: var(--chart-away)" />
        </svg>

        @php $last = end($points); @endphp

        <p class="text-micro text-zinc-500">
            @if ($game->completed)
                Final:
            @else
                Now:
            @endif
            <span class="font-semibold text-zinc-700 dark:text-zinc-200">
                {{ $last >= 50 ? $game->homeTeam?->abbreviation : $game->awayTeam?->abbreviation }}
                {{ rtrim(rtrim(number_format($last >= 50 ? $last : 100 - $last, 1), '0'), '.') }}%
            </span>
        </p>
    </div>
@endif
