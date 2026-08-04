@props(['game'])

@php
    /*
     * The betting line, as ESPN carries it under each matchup.
     *
     * Prefers the CURRENT line and falls back to the opening one. Those are
     * separate rows rather than columns because ESPN's own opening line is not
     * retrievable — a completed game returns `odds: null` — so we freeze our
     * first observation as `open` and accumulate movement from there. It cannot
     * be backfilled, which is why the fallback matters: an older game may only
     * ever have had one observation.
     */
    $odds = $game->relationLoaded('odds')
        ? $game->odds->firstWhere('phase', 'current') ?? $game->odds->first()
        : $game->odds()->orderByRaw("FIELD(phase, 'current', 'open')")->first();

    $moneyline = $odds?->moneyline_away ?? $odds?->moneyline_home;
    $moneylineTeam = $odds?->moneyline_away !== null ? $game->awayTeam : $game->homeTeam;
@endphp

@if ($odds && ($odds->details || $odds->spread !== null || $odds->over_under !== null))
    <div {{ $attributes->class(['flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md bg-zinc-50 px-2.5 py-1.5 text-micro text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400']) }}>
        @if ($odds->details || $odds->spread !== null)
            <span>
                Spread:
                <span class="font-semibold text-zinc-700 dark:text-zinc-200">
                    {{ $odds->details ?: $odds->spread }}
                </span>
            </span>
        @endif

        @if ($odds->over_under !== null)
            <span>
                Total:
                <span class="font-semibold text-zinc-700 dark:text-zinc-200">
                    {{ rtrim(rtrim(number_format($odds->over_under, 1), '0'), '.') }}
                </span>
            </span>
        @endif

        @if ($moneyline !== null)
            <span>
                ML:
                <span class="font-semibold text-zinc-700 dark:text-zinc-200">
                    {{ $moneylineTeam?->abbreviation }} {{ $moneyline > 0 ? '+' : '' }}{{ $moneyline }}
                </span>
            </span>
        @endif
    </div>
@endif
