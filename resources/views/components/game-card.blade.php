@props(['game'])

@php
    $live = $game->status === 'in';
    $final = $game->completed;
    $winner = $game->winnerTeamId();

    $sides = [
        ['team' => $game->awayTeam, 'score' => $game->away_score, 'rank' => $game->away_rank, 'record' => $game->away_record],
        ['team' => $game->homeTeam, 'score' => $game->home_score, 'rank' => $game->home_rank, 'record' => $game->home_record],
    ];
@endphp

<div {{ $attributes->class(['flex flex-col rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900']) }}>
    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 px-3 py-1.5 text-micro dark:border-zinc-800/60">
        <span class="flex min-w-0 items-center gap-1.5 text-zinc-500">
            @if ($game->conference_game)
                <span class="shrink-0 rounded bg-zinc-100 px-1 py-px font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">Conf</span>
            @endif
            <span class="truncate">{{ $game->venue?->name ?? 'Venue TBD' }}</span>
        </span>

        @if ($live)
            <span class="flex shrink-0 items-center gap-1 font-semibold text-red-600 dark:text-red-400">
                <span class="size-1.5 animate-pulse rounded-full bg-current"></span>
                {{ $game->status_detail ?? 'Live' }}
            </span>
        @elseif ($final)
            <span class="shrink-0 font-medium text-zinc-500">Final</span>
        @else
            <span class="shrink-0 font-medium text-zinc-600 dark:text-zinc-400">
                {{ $game->kickoff_at->setTimezone(config('cfb.timezone'))->format('g:ia') }}
            </span>
        @endif
    </div>

    <div class="flex flex-col gap-1.5 px-3 py-2.5">
        @foreach ($sides as $side)
            @php $lost = $final && $winner !== null && $winner !== $side['team']?->id; @endphp

            <div class="flex items-center gap-2">
                <x-team-link
                    :team="$side['team']"
                    :rank="$side['rank']"
                    :record="$side['record']"
                    :muted="$lost"
                    class="flex-1"
                />

                <span @class([
                    'tabular w-7 shrink-0 text-right text-sm tracking-tight',
                    'font-bold' => $final && $winner === $side['team']?->id,
                    'font-semibold' => ! $final || $winner !== $side['team']?->id,
                    'text-zinc-400' => $lost,
                ])>
                    {{ $final || $live ? $side['score'] : '' }}
                </span>
            </div>
        @endforeach
    </div>
</div>
