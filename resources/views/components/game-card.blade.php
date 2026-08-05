{{--
    `date` prepends a short m/d to an UPCOMING game's kickoff line.

    Off by default because most surfaces already carry the date around the
    card: the scoreboard groups by day heading, and Home shows a single week.
    A team's schedule is the exception — it is a flat list spanning four
    months, where "7:30pm" alone says nothing about which Saturday.
--}}
@props(['game', 'odds' => true, 'date' => false])

@php
    $live = $game->status === 'in';
    $final = $game->completed;
    $winner = $game->winnerTeamId();

    $sides = [
        ['team' => $game->awayTeam, 'score' => $game->away_score, 'rank' => $game->away_rank, 'record' => $game->away_record],
        ['team' => $game->homeTeam, 'score' => $game->home_score, 'rank' => $game->home_rank, 'record' => $game->home_record],
    ];

    /*
     * The event's own name — "Rose Bowl Presented by Prudential", "College
     * Football Playoff National Championship".
     *
     * Read from `note`, not `name`: `name` is only ever "A at B", so every bowl
     * rendered as an ordinary fixture and there was no way to tell the National
     * Championship from a Tuesday MAC game. Only postseason games carry a note,
     * which is exactly why its presence is meaningful.
     */
    $event = $game->note;

    $broadcast = collect($game->broadcasts ?? [])->flatten()->filter()->first();
@endphp

{{--
    `min-w-0` is load-bearing, not tidying.

    This card is a grid item, and a grid item's automatic minimum size is its
    MIN-CONTENT width. The event caption below is `truncate`, which sets
    `white-space: nowrap` — and the min-content width of unwrappable text is the
    whole string. So the card refused to shrink below the longest bowl name,
    grew to 404px inside a 343px track, and pushed the document sideways.

    `truncate` cannot save it: clipping needs a constrained box, and the box was
    growing to fit the text instead of the other way round.

    It surfaced on the CFP bracket because that is where the longest strings
    live — "College Football Playoff Quarterfinal at the Chick-fil-A Peach Bowl"
    against an ordinary bowl's much shorter name.
--}}
<div {{ $attributes->class(['flex min-w-0 flex-col rounded-lg border border-zinc-200 bg-white transition-colors hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700']) }}>
    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 px-3 py-1.5 text-micro dark:border-zinc-800/60">
        {{-- No "Conf" badge. Anyone reading a college football scoreboard knows
             which matchups are in-conference from the teams themselves, so it
             was a chip spending width to say nothing. `conference_game` is
             still synced and still filters standings. --}}
        <span class="flex min-w-0 items-center gap-1.5 text-zinc-500">
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
            {{-- Scheduled: this branch is upcoming games only — a final says
                 "Final" and a live game says its clock — so the date lands
                 exactly where it is useful and nowhere else. --}}
            <span class="shrink-0 text-right font-medium text-zinc-600 dark:text-zinc-400">
                @if ($date)
                    <span class="tabular text-zinc-500">{{ $game->kickoff_at->setTimezone(config('cfb.timezone'))->format('n/j') }}</span>
                    <span class="text-zinc-400">·</span>
                @endif
                {{ $game->kickoff_at->setTimezone(config('cfb.timezone'))->format('g:ia') }}
                @if ($broadcast)
                    <span class="text-zinc-400">· {{ $broadcast }}</span>
                @endif
            </span>
        @endif
    </div>

    {{--
        The WHOLE card goes to the game page — one destination, one tap.
        Team names render as plain text (`:link="false"`), because a reader
        tapping a game card wants the game; the teams are one more tap away
        on the Game screen itself. Everything above the overlay is
        `pointer-events-none` so every tap falls through to the anchor.
    --}}
    <div class="relative flex flex-col gap-1.5 px-3 py-2.5">
        <a
            href="{{ route('game', $game) }}"
            wire:navigate
            class="absolute inset-0 z-0"
            aria-label="{{ $game->short_name ?? $game->name }}"
        ></a>

        @if ($event)
            <p class="pointer-events-none relative z-10 truncate text-micro text-zinc-500">{{ $event }}</p>
        @endif

        @foreach ($sides as $side)
            @php $lost = $final && $winner !== null && $winner !== $side['team']?->id; @endphp

            <div class="pointer-events-none relative z-10 flex items-center gap-2">
                {{-- Place only, no nickname. A card is scanned, not read: the
                     reader is looking for "North Carolina", and "Tar Heels" is
                     nine characters of decoration in front of the next team's
                     name. --}}
                <x-team-link
                    :team="$side['team']"
                    :rank="$side['rank']"
                    :record="$side['record']"
                    :muted="$lost"
                    :link="false"
                    label="location"
                    class="flex-1"
                />

                <span @class([
                    'tabular pointer-events-none w-7 shrink-0 text-right text-sm tracking-tight',
                    'font-bold' => $final && $winner === $side['team']?->id,
                    'font-semibold' => ! $final || $winner !== $side['team']?->id,
                    'text-zinc-400' => $lost,
                ])>
                    {{ $final || $live ? $side['score'] : '' }}
                </span>
            </div>
        @endforeach
    </div>

    @if ($odds && ! $final)
        <div class="px-3 pb-2.5">
            <x-odds-strip :game="$game" />
        </div>
    @endif
</div>
