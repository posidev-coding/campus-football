@props([
    'limit' => 5,
])

@php
    use App\Models\Game;
    use App\Models\Week;
    use App\Services\CfbCalendar;
    use App\Support\Remember;

    $calendar = app(CfbCalendar::class);

    /*
     * `scoreboardYear()`, not the results year: this is the week being played,
     * and asking for the season last PLAYED serves finished bowl games under a
     * "This week" heading all summer.
     */
    $week = Week::find($calendar->defaultWeekId($calendar->scoreboardYear()));

    /*
     * 300s, not 900: these carry live scores, and a stale scoreboard is worse
     * than no scoreboard. Ids only — an Eloquent collection in the cache
     * returns as __PHP_Incomplete_Class on the SECOND request, never the first.
     */
    $ids = $week === null ? [] : Remember::filled(
        "rail:week:{$week->id}:{$limit}",
        300,
        fn () => Game::query()
            ->where('week_id', $week->id)
            ->leftJoin('game_predictors as gp', 'gp.game_id', '=', 'games.id')
            ->select('games.id')
            // Lead with the matchups worth watching. NULLs last, so games ESPN
            // has not modelled do not outrank ones it has.
            ->orderByRaw('gp.matchup_quality IS NULL, gp.matchup_quality DESC')
            ->orderBy('games.kickoff_at')
            ->limit($limit)
            ->pluck('games.id')
            ->all()
    );

    /*
     * `location` is not optional in this eager load: the game card renders
     * placeName(), and omitting the column makes every team quietly fall back
     * to its display name — which reads as a design choice, not a bug.
     *
     * `venue` and `odds` are not optional either, for a blunter reason: lazy
     * loading is disabled app-wide, so a relation the card reads and this
     * query does not load is a 500, not a missing line.
     */
    $columns = 'id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark';

    $games = $ids === []
        ? collect()
        : Game::query()
            ->with(['homeTeam:'.$columns, 'awayTeam:'.$columns, 'venue:id,name', 'odds'])
            ->whereIn('id', $ids)
            ->orderBy('kickoff_at')
            ->get();
@endphp

@if ($games->isNotEmpty())
    <section {{ $attributes->class(['flex shrink-0 flex-col rounded-lg border border-zinc-200 dark:border-zinc-800']) }}>
        <header class="flex items-baseline justify-between gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">
            <h2 class="text-sm font-semibold">{{ $week?->name ?? 'This week' }}</h2>
            <a href="{{ route('scoreboard') }}" wire:navigate
               class="shrink-0 text-micro text-zinc-500 hover:text-zinc-900 hover:underline dark:hover:text-zinc-100">
                All scores
            </a>
        </header>

        <div class="flex flex-col gap-2 p-2">
            @foreach ($games as $game)
                <x-game-card :game="$game" date wire:key="rail-game-{{ $game->id }}" />
            @endforeach
        </div>
    </section>
@endif
