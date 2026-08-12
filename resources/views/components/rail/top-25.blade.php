@props([
    'poll' => null,
    'limit' => 25,
])

@php
    use App\Enums\Poll;
    use App\Models\Ranking;
    use App\Models\Season;
    use App\Support\Remember;

    // CFP once the committee has released one, AP until then.
    $calendar = app(App\Services\CfbCalendar::class);
    $pollKey = $poll ?? $calendar->defaultPoll()->value;
    $pollLabel = (Poll::tryFrom($pollKey) ?? Poll::Ap)->label();

    /*
     * Resolved OUTSIDE the cache and folded into the key. A calendar fallback
     * pinned inside a cache entry is how a screen keeps serving last season
     * for a full TTL, and keying on the release is what lets a new poll
     * publish without waiting the TTL out.
     */
    $year = $calendar->rankingsYear($pollKey);
    $release = $calendar->latestRankingRelease($year, $pollKey);

    /*
     * Remember::filled rather than Cache::remember: this list gates the whole
     * rail, and in August the only poll published is the preseason one. An
     * empty answer here is a moment, not a fact — pinning it for 900s while a
     * sync drains is the exact bug Remember exists to prevent. Recomputing an
     * empty costs two indexed queries returning no rows.
     *
     * Cached as plain arrays rather than models — Eloquent collections
     * round-trip through Redis as __PHP_Incomplete_Class and fail on the
     * second request, not the first.
     */
    $rankings = Remember::filled(
        "rail:top25:{$pollKey}:{$year}:{$release}:{$limit}",
        900,
        function () use ($pollKey, $year, $release, $limit) {
            /*
             * Spans season types. The preseason poll lives on the type 1
             * season row and the final rankings on type 3, so filtering to
             * REGULAR empties this panel for the whole summer — which is
             * exactly what it did.
             */
            $seasonIds = Season::where('year', $year)->pluck('id');

            if ($seasonIds->isEmpty()) {
                return [];
            }

            return Ranking::query()
                ->whereIn('season_id', $seasonIds)
                ->where('poll', $pollKey)
                ->when($release, fn ($q) => $q->where('week_id', $release))
                ->orderBy('rank')
                ->limit($limit)
                ->get(['rank', 'previous_rank', 'record', 'team_id'])
                ->map(fn (Ranking $r) => [
                    'rank' => $r->rank,
                    'previous' => $r->previous_rank,
                    'record' => $r->record,
                    'team_id' => $r->team_id,
                ])
                ->all();
        }
    );

    $teams = $rankings === []
        ? collect()
        : App\Models\Team::whereIn('id', array_column($rankings, 'team_id'))
            ->get(['id', 'slug', 'display_name', 'short_display_name', 'abbreviation', 'logo', 'logo_dark'])
            ->keyBy('id');
@endphp

@if ($rankings !== [])
    {{-- No sticky here: the rail's own wrapper sticks the whole stack. --}}
    <section {{ $attributes->class(['flex shrink-0 flex-col rounded-lg border border-zinc-200 dark:border-zinc-800']) }}>
        <header class="flex items-baseline justify-between gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">
            <h2 class="text-sm font-semibold">{{ $pollLabel }}</h2>
            <a href="{{ route('rankings') }}" wire:navigate
               class="shrink-0 text-micro text-zinc-500 hover:text-zinc-900 hover:underline dark:hover:text-zinc-100">
                All polls
            </a>
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
