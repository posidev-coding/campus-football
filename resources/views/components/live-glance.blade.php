@props(['game', 'team'])

{{--
    The condensed live game, for a followed team's home card.

    It REPLACES the next and last rows rather than joining them: while a team
    is playing, the next fixture and last week's result are both the wrong
    answer to "what is happening", and three rows in a glance card is a list.

    The whole block is one link to the GAME, while the card's header links to
    the team — anchors do not nest, which is why the card was built as a header
    link plus independent game rows in the first place. Both destinations stay
    one tap away and neither swallows the other.

    Everything below the score rows is optional and must read right without it.
    A live payload that omits the situation block is a transient gap, not an
    absence — the sync deliberately leaves the columns alone rather than
    nulling real data over a momentary silence, so this has to tolerate any
    subset arriving.
--}}
@php
    $sides = [
        ['team' => $game->awayTeam, 'id' => $game->away_team_id, 'score' => $game->away_score],
        ['team' => $game->homeTeam, 'id' => $game->home_team_id, 'score' => $game->home_score],
    ];

    $status = $game->liveStatusLine();
    $situation = $game->down_distance_text || $game->last_play_text;
@endphp

<a
    href="{{ route('game', $game) }}"
    wire:navigate
    class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-2.5 transition-colors hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-900"
    wire:key="glance-live-{{ $game->id }}"
>
    <div class="flex items-center justify-between gap-2">
        <span class="flex items-center gap-1.5 text-micro font-semibold text-red-600 dark:text-red-400">
            {{-- The ping is decoration over a solid dot, so a reduced-motion
                 reader still sees the mark rather than nothing. --}}
            <span class="relative flex size-1.5">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75 motion-reduce:hidden"></span>
                <span class="relative inline-flex size-1.5 rounded-full bg-red-500"></span>
            </span>
            LIVE
        </span>

        @if ($status)
            <span class="tabular shrink-0 text-micro text-zinc-500">{{ $status }}</span>
        @endif
    </div>

    {{-- Away over home, the order every scoreboard uses. The followed team is
         bolded rather than floated to the top: moving it would make two of a
         reader's cards disagree about which row is which team. --}}
    <div class="flex flex-col gap-1">
        @foreach ($sides as $side)
            <div class="flex items-center gap-2">
                <x-team-logo :team="$side['team']" size="xs" class="shrink-0" />

                <span @class([
                    'min-w-0 flex-1 truncate text-sm',
                    'font-semibold' => $side['id'] === $team->id,
                    'text-zinc-500' => $side['id'] !== $team->id,
                ])>{{ $side['team']?->placeName() ?? 'TBD' }}</span>

                @if ($game->possession_team_id && $game->possession_team_id === $side['id'])
                    {{-- Position carries this, not color: it sits against one
                         team's row and nowhere else. --}}
                    <span class="size-1.5 shrink-0 rounded-full bg-amber-500" title="Has possession">
                        <span class="sr-only">Has possession</span>
                    </span>
                @endif

                <span class="tabular shrink-0 text-sm font-bold">{{ $side['score'] ?? 0 }}</span>
            </div>
        @endforeach
    </div>

    @if ($situation)
        {{-- Two lines held open. Down and distance clears the moment a play
             ends and returns on the next snap, so without a floor this block
             would breathe every thirty seconds and shift the news beneath it. --}}
        <div class="flex min-h-8 flex-col gap-0.5 border-t border-zinc-100 pt-1.5 dark:border-zinc-800/60">
            @if ($game->down_distance_text)
                <span @class([
                    'truncate text-micro font-medium',
                    'text-red-600 dark:text-red-400' => $game->is_red_zone,
                    'text-zinc-600 dark:text-zinc-300' => ! $game->is_red_zone,
                ])>
                    {{ $game->down_distance_text }}@if ($game->is_red_zone) · Red zone @endif
                </span>
            @endif

            {{-- One line, clipped. A real play description runs past the card
                 and a block that changes height as plays land is worse than a
                 clipped sentence. --}}
            @if ($game->last_play_text)
                <span class="truncate text-micro text-zinc-500">{{ $game->last_play_text }}</span>
            @endif
        </div>
    @endif
</a>
