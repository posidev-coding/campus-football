@props([
    'poll' => 'ap',
    'limit' => 25,
])

@php
    use App\Models\Ranking;
    use App\Models\Season;
    use Illuminate\Support\Facades\Cache;

    /*
     * The right rail's anchor. Cached as plain arrays rather than models —
     * Eloquent collections round-trip through Redis as __PHP_Incomplete_Class
     * and fail on the second request, not the first.
     */
    $rankings = Cache::remember("panel:rankings:{$poll}:{$limit}", 900, function () use ($poll, $limit) {
        /*
         * The most recent season that actually has this poll — not simply the
         * most recent season. An upcoming season exists in the database well
         * before any poll is published for it, and selecting on year alone
         * silently emptied the whole panel.
         */
        $season = Season::query()
            ->whereIn('id', Ranking::where('poll', $poll)->distinct()->pluck('season_id'))
            ->orderByDesc('year')
            ->first();

        if ($season === null) {
            return [];
        }

        $latestWeek = Ranking::where('season_id', $season->id)->where('poll', $poll)->max('week_id');

        return Ranking::query()
            ->with('team:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark')
            ->where('season_id', $season->id)
            ->where('poll', $poll)
            ->when($latestWeek, fn ($q) => $q->where('week_id', $latestWeek))
            ->orderBy('rank')
            ->limit($limit)
            ->get()
            ->map(fn (Ranking $r) => [
                'rank' => $r->rank,
                'previous' => $r->previous_rank,
                'record' => $r->record,
                'team_id' => $r->team_id,
            ])
            ->all();
    });

    $teams = $rankings === []
        ? collect()
        : App\Models\Team::whereIn('id', array_column($rankings, 'team_id'))
            ->get(['id', 'slug', 'display_name', 'short_display_name', 'abbreviation', 'logo', 'logo_dark'])
            ->keyBy('id');
@endphp

@if ($rankings !== [])
    <section {{ $attributes->class(['flex flex-col rounded-lg border border-zinc-200 dark:border-zinc-800']) }}>
        <header class="flex items-baseline justify-between border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">
            <h2 class="text-sm font-semibold">AP Top 25</h2>
            <span class="text-micro text-zinc-500">Latest poll</span>
        </header>

        <ol class="flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800/60">
            @foreach ($rankings as $entry)
                @php
                    $team = $teams->get($entry['team_id']);
                    $movement = $entry['previous'] ? $entry['previous'] - $entry['rank'] : 0;
                @endphp

                <li class="flex items-center gap-2 px-3 py-1.5">
                    <span class="tabular w-5 shrink-0 text-right text-stat font-semibold text-zinc-400">
                        {{ $entry['rank'] }}
                    </span>

                    <x-team-link :team="$team" label="short" size="xs" class="min-w-0 flex-1" />

                    <span class="tabular shrink-0 text-micro text-zinc-400">{{ $entry['record'] }}</span>

                    @if ($movement > 0)
                        <span class="shrink-0 text-micro font-medium text-emerald-600 dark:text-emerald-400">▲{{ $movement }}</span>
                    @elseif ($movement < 0)
                        <span class="shrink-0 text-micro font-medium text-red-600 dark:text-red-400">▼{{ abs($movement) }}</span>
                    @else
                        <span class="w-4 shrink-0"></span>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif
