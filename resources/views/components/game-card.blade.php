@props(['game'])

@php
    $live = $game->status === 'in';
    $final = $game->completed;
    $winner = $game->winnerTeamId();
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col gap-2 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900']) }}>
    <div class="flex items-center justify-between gap-2 text-micro">
        <span class="truncate text-zinc-500">
            @if ($game->conference_game)
                <span class="font-medium text-zinc-600 dark:text-zinc-400">Conf</span> &middot;
            @endif
            {{ $game->venue?->name ?? 'TBD' }}
        </span>

        @if ($live)
            <span class="flex shrink-0 items-center gap-1 font-semibold text-red-600 dark:text-red-400">
                <span class="size-1.5 animate-pulse rounded-full bg-red-600 dark:bg-red-400"></span>
                {{ $game->status_detail ?? 'Live' }}
            </span>
        @elseif ($final)
            <span class="shrink-0 font-medium text-zinc-500">Final</span>
        @else
            <span class="shrink-0 text-zinc-500">
                {{ $game->kickoff_at->setTimezone(config('cfb.timezone'))->format('g:ia') }}
            </span>
        @endif
    </div>

    <div class="flex flex-col gap-1">
        @foreach ([['away', $game->awayTeam, $game->away_score, $game->away_rank, $game->away_record], ['home', $game->homeTeam, $game->home_score, $game->home_rank, $game->home_record]] as [$side, $team, $score, $rank, $record])
            @php $lost = $final && $winner !== null && $winner !== $team?->id; @endphp

            <div class="flex items-center gap-2 {{ $lost ? 'opacity-45' : '' }}">
                @if ($team?->logo)
                    <img
                        src="{{ $team->logo }}"
                        alt=""
                        loading="lazy"
                        class="size-6 shrink-0 object-contain"
                    >
                @else
                    <div class="size-6 shrink-0 rounded-full bg-zinc-200 dark:bg-zinc-700"></div>
                @endif

                @if ($rank)
                    <span class="shrink-0 text-micro font-semibold text-zinc-500">{{ $rank }}</span>
                @endif

                <span class="min-w-0 flex-1 truncate text-sm {{ $final && $winner === $team?->id ? 'font-semibold' : '' }}">
                    {{ $team?->display_name ?? 'TBD' }}
                </span>

                @if ($record)
                    <span class="shrink-0 text-micro text-zinc-400">{{ $record }}</span>
                @endif

                <span class="tabular w-7 shrink-0 text-right text-sm font-semibold">
                    {{ $final || $live ? $score : '' }}
                </span>
            </div>
        @endforeach
    </div>
</div>
