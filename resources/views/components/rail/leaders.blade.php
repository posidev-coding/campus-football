@props([
    'limit' => 5,
])

@php
    use App\Models\AthleteSeasonStat;
    use App\Models\Athlete;
    use App\Models\Team;
    use App\Support\Remember;
    use App\Support\Stats\LeaderQuery;

    /*
     * Three headline boards, short. The rail is 288px wide, so a board that
     * runs to ten names becomes a column of its own — five is the length that
     * reads as a summary rather than a leaderboard, and "All stats" carries
     * anyone who wants the rest.
     */
    $boards = [
        ['group' => 'Passing', 'category' => 'passing', 'stat' => 'passingYards', 'label' => 'Passing Yards', 'decimals' => 0],
        ['group' => 'Rushing', 'category' => 'rushing', 'stat' => 'rushingYards', 'label' => 'Rushing Yards', 'decimals' => 0],
        ['group' => 'Receiving', 'category' => 'receiving', 'stat' => 'receivingYards', 'label' => 'Receiving Yards', 'decimals' => 0],
    ];

    /*
     * The same derived year the Stats screen uses, under the SAME cache key —
     * the stats tables lag the season being played by design, so asking the
     * calendar for the current year yields empty boards all autumn. Two keys
     * for one question would drift the moment one of them was flushed.
     */
    $year = Remember::filled('leaders:derived-year', 3600, fn () => AthleteSeasonStat::max('season_year'))
        ?? app(App\Services\CfbCalendar::class)->resultsYear();

    // LeaderQuery caches each board itself at 900s, so nothing is cached here.
    $rows = collect($boards)
        ->map(fn (array $board) => [
            'label' => $board['label'],
            'rows' => LeaderQuery::players($board, $year, 'fbs', $limit),
        ])
        ->filter(fn (array $board) => $board['rows'] !== [])
        ->values()
        ->all();

    // Hydrated once across all three boards rather than per board, and with
    // every column the links read: `slug` is the athlete route key, and a
    // team missing `location` silently renders its display name instead of
    // its place.
    $athleteIds = collect($rows)->flatMap(fn (array $b) => array_column($b['rows'], 'athlete_id'))->unique();
    $teamIds = collect($rows)->flatMap(fn (array $b) => array_column($b['rows'], 'team_id'))->filter()->unique();

    // Athletes route by id — the model deliberately has no getRouteKeyName,
    // because 326 athlete slugs collide.
    $athletes = $athleteIds->isEmpty()
        ? collect()
        : Athlete::whereIn('id', $athleteIds)->get(['id', 'display_name', 'short_name'])->keyBy('id');

    $teams = $teamIds->isEmpty()
        ? collect()
        : Team::whereIn('id', $teamIds)
            ->get(['id', 'slug', 'location', 'display_name', 'short_display_name', 'abbreviation', 'logo', 'logo_dark'])
            ->keyBy('id');
@endphp

@if ($rows !== [])
    <section {{ $attributes->class(['flex shrink-0 flex-col rounded-lg border border-zinc-200 dark:border-zinc-800']) }}>
        <header class="flex items-baseline justify-between gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">
            <h2 class="text-sm font-semibold">Leaders</h2>
            <a href="{{ route('stats') }}" wire:navigate
               class="shrink-0 text-micro text-zinc-500 hover:text-zinc-900 hover:underline dark:hover:text-zinc-100">
                All stats
            </a>
        </header>

        <div class="flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800/60">
            @foreach ($rows as $board)
                <div class="flex flex-col gap-1 px-3 py-2" wire:key="rail-board-{{ $board['label'] }}">
                    <h3 class="text-micro font-medium tracking-wide text-zinc-500 uppercase">{{ $board['label'] }}</h3>

                    <ol class="flex flex-col gap-0.5">
                        @foreach ($board['rows'] as $row)
                            @php
                                $athlete = $athletes->get($row['athlete_id']);
                                $team = $row['team_id'] ? $teams->get($row['team_id']) : null;
                            @endphp

                            @continue($athlete === null)

                            <li class="flex items-center gap-2" wire:key="rail-leader-{{ $board['label'] }}-{{ $row['athlete_id'] }}">
                                <span class="tabular w-3 shrink-0 text-right text-micro text-zinc-400">{{ $row['rank'] }}</span>

                                <a href="{{ route('player', $athlete) }}" wire:navigate
                                   class="min-w-0 flex-1 truncate text-stat hover:underline">
                                    {{ $athlete->short_name ?? $athlete->display_name }}
                                </a>

                                @if ($team)
                                    <span class="shrink-0 text-micro text-zinc-400">{{ $team->abbreviation }}</span>
                                @endif

                                <span class="tabular shrink-0 text-stat font-semibold">{{ $row['display'] }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endforeach
        </div>
    </section>
@endif
