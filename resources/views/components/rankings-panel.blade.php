@props([
    'poll' => null,
    'limit' => 25,
])

@php
    use App\Enums\Poll;
    use App\Models\Ranking;
    use App\Models\Season;
    use Illuminate\Support\Facades\Cache;

    // CFP once the committee has released one, AP until then.
    $calendar = app(App\Services\CfbCalendar::class);
    $pollKey = $poll ?? $calendar->defaultPoll()->value;
    $pollLabel = (Poll::tryFrom($pollKey) ?? Poll::Ap)->label();

    /*
     * The right rail's anchor. Cached as plain arrays rather than models —
     * Eloquent collections round-trip through Redis as __PHP_Incomplete_Class
     * and fail on the second request, not the first.
     */
    $rankings = Cache::remember("panel:rankings:{$pollKey}:{$limit}", 900, function () use ($pollKey, $limit) {
        // CfbCalendar knows which season actually has this poll — an upcoming
        // season exists long before a poll is published for it.
        $year = app(App\Services\CfbCalendar::class)->rankingsYear($pollKey);
        $season = Season::where('year', $year)->where('type', Season::REGULAR)->first();

        if ($season === null) {
            return [];
        }

        $latestWeek = Ranking::where('season_id', $season->id)->where('poll', $pollKey)->max('week_id');

        return Ranking::query()
            ->with('team:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark')
            ->where('season_id', $season->id)
            ->where('poll', $pollKey)
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
