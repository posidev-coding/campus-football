@php
    use App\Models\Game;
    use App\Models\Season;
    use App\Models\Team;
    use App\Services\CfbCalendar;

    /*
     * The team is read straight off the route. Route-model binding has already
     * resolved and hydrated it by the time the layout renders, and that binding
     * is unconstrained, so every column the game card reads is present — no
     * second lookup, and none of the constrained-eager-load traps.
     *
     * The rail persists across all five team-nav tabs, which is what earns
     * this panel its place beside a Schedule tab that shows the same games:
     * the next kickoff stays on screen while a reader is on Roster or Stats.
     */
    $team = request()->route('team');

    $games = collect();

    if ($team instanceof Team) {
        /*
         * `location` keeps the card naming the PLACE rather than silently
         * falling back to the display name; `venue` and `odds` are relations
         * the card reads, and lazy loading is disabled — a missing one is a
         * 500 rather than a blank line.
         */
        $columns = 'id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark';
        $with = ['homeTeam:'.$columns, 'awayTeam:'.$columns, 'venue:id,name', 'odds'];

        $isTeam = fn ($q) => $q->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id);

        /*
         * The last result is season-scoped, or this walks back through a
         * decade of history. `resultsYear()` — the latest season that HAS
         * games played — not the season being played, which in the offseason
         * contains nothing completed at all.
         */
        $seasonIds = Season::where('year', app(CfbCalendar::class)->resultsYear())->pluck('id');

        $last = Game::query()
            ->with($with)
            ->whereIn('season_id', $seasonIds)
            ->where('completed', true)
            ->where($isTeam)
            ->orderByDesc('kickoff_at')
            ->limit(1)
            ->get();

        /*
         * Next and live are deliberately NOT season-scoped. In August the
         * results year is last season with every game in it complete, and the
         * next kickoff belongs to a season that has not started counting yet.
         */
        $next = Game::query()
            ->with($with)
            ->where('completed', false)
            ->where($isTeam)
            ->where(fn ($q) => $q
                ->where('kickoff_at', '>=', now())
                ->orWhereIn('status', ['in', 'halftime', 'end-period']))
            ->orderBy('kickoff_at')
            ->limit(1)
            ->get();

        $games = $next->concat($last);
    }
@endphp

@if ($games->isNotEmpty())
    <section {{ $attributes->class(['flex shrink-0 flex-col rounded-lg border border-zinc-200 dark:border-zinc-800']) }}>
        <header class="flex items-baseline justify-between gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">
            <h2 class="text-sm font-semibold">Next &amp; last</h2>
            <a href="{{ route('team', $team) }}" wire:navigate
               class="shrink-0 text-micro text-zinc-500 hover:text-zinc-900 hover:underline dark:hover:text-zinc-100">
                Schedule
            </a>
        </header>

        <div class="flex flex-col gap-2 p-2">
            @foreach ($games as $game)
                <x-game-card :game="$game" date wire:key="rail-team-game-{{ $game->id }}" />
            @endforeach
        </div>
    </section>
@endif
