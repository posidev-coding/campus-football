{{--
    THE PICK CARD — a real matchup card whose two sides are the control.

    Grown from x-game-card's DNA (caption row, ranks from GameRanks, records,
    place names) but the body is two full-width BUTTONS rather than one link:
    tapping a side IS the pick, and the picked side fills with that team's
    color via the same TeamPalette custom properties the team pages brand
    with. Dark mode un-brands by house rule, so selection carries a second
    signal there — the check mark and the light border, never color alone.

    The card body is deliberately NOT a link (the game-card's whole-card
    anchor would fight the buttons); the small caption chevron is the one
    road to the game page, because scouting the matchup is half the fun.

    The side's number is the CONTEST line read as that side's burden:
    -6.5 on the favorite, +6.5 on the dog — derived from magnitude plus
    favorite_team_id, never from the stored sign (the home-handicap trap).

    `interactive: false` renders the same card as a preview — no taps, no
    "No pick" verdicts — for outsiders reading a lobby and the commissioner's
    preview-as-participant step.

    Woodshed extras: `bearTeamId` pins the Bear's paw on his side (his picks
    are public by design — the house's creature, not a Pick row), `featured`
    renames the tiebreaker chip in the founders' vocabulary, and `lockable`
    grows the Lock footer — the +6/−4 wager on this one game. Two "locked"s
    meet here on purpose: the `locked` PROP is temporal (kickoff passed),
    `$pick->locked` is the staked wager.
--}}
@props([
    'slateGame',
    /** The VIEWER's own pick — everyone else's stays behind Pick::visibleTo. */
    'pick' => null,
    'locked' => false,
    'interactive' => true,
    'tiebreaker' => false,
    /** What a win here pays, from the mode engine. */
    'points' => null,
    /** The Bear's side of this game, when the slate fields him. */
    'bearTeamId' => null,
    /** Woodshed vocabulary: the tiebreaker game is the featured game. */
    'featured' => false,
    /** Whether this card takes the Lock wager (Woodshed featured game). */
    'lockable' => false,
])

@php
    $game = $slateGame->game;
    $live = $game->isInProgress();
    $final = $game->completed;
    $graded = $pick?->result !== null;

    $ranks = App\Support\GameRanks::forGame($game);

    $sides = [
        ['team' => $game->awayTeam, 'score' => $game->away_score, 'rank' => $ranks['away'], 'record' => $game->away_record],
        ['team' => $game->homeTeam, 'score' => $game->home_score, 'rank' => $ranks['home'], 'record' => $game->home_record],
    ];

    $burden = $slateGame->spread === null ? null : abs($slateGame->spread);
@endphp

<div {{ $attributes->class(['flex min-w-0 flex-col rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900']) }}>
    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 px-3 py-1.5 text-micro dark:border-zinc-800/60">
        <span class="flex min-w-0 items-center gap-1.5">
            @if ($live)
                <span class="flex shrink-0 items-center gap-1 font-semibold text-red-600 dark:text-red-400">
                    <span class="size-1.5 animate-pulse rounded-full bg-current"></span>
                    {{ $game->status_detail ?? 'Live' }}
                </span>
            @elseif ($final)
                <span class="shrink-0 font-medium text-zinc-500">Final</span>
            @else
                <span class="shrink-0 font-medium text-zinc-600 dark:text-zinc-400">
                    {{ $game->kickoff_at?->setTimezone(config('cfb.timezone'))->format('D g:ia') ?? 'TBD' }}
                </span>
                @if ($locked)
                    {{-- The state a reader scans for; it stays plain. --}}
                    <span class="shrink-0 font-semibold text-zinc-500">· Locked</span>
                @endif
            @endif
        </span>

        <span class="flex shrink-0 items-center gap-1.5">
            @if ($graded)
                @if ($pick->result === App\Models\Pick::WIN)
                    <span class="flex items-center gap-1 font-semibold text-emerald-700 dark:text-emerald-400">
                        <flux:icon.check-circle-fill variant="micro" class="size-3.5" />
                        +{{ $pick->points ?? 0 }}
                    </span>
                @else
                    <span class="flex items-center gap-1 font-semibold text-red-600 dark:text-red-400">
                        <flux:icon.x-circle-fill variant="micro" class="size-3.5" />
                        {{-- A backfired Lock is a real MINUS, printed honestly. --}}
                        {{ $pick->points ?? 0 }}
                    </span>
                @endif
            @elseif ($interactive && $locked && $pick === null)
                {{-- An absent pick is an absent row worth zero — said honestly,
                     never a side quietly filled in. --}}
                <span class="font-medium text-zinc-400">No pick · 0</span>
            @endif

            @if ($slateGame->tier !== null)
                <span class="rounded bg-zinc-100 px-1.5 py-0.5 font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    Tier {{ $slateGame->tier }}@if ($points !== null) · {{ $points }} {{ Str::plural('pt', $points) }}@endif
                </span>
            @endif

            @if ($tiebreaker)
                <span class="rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-300">{{ $featured ? 'Featured' : 'Tiebreaker' }}</span>
            @endif

            <a
                href="{{ route('game', $game) }}"
                wire:navigate
                class="-my-1 -me-1.5 rounded p-1 text-zinc-400 transition-colors hover:text-zinc-600 dark:hover:text-zinc-300"
                aria-label="{{ $game->short_name ?? $game->name }}"
            >
                <flux:icon name="chevron-right" variant="micro" />
            </a>
        </span>
    </div>

    <div class="flex flex-col gap-1.5 px-2.5 py-2.5">
        @foreach ($sides as $side)
            @php
                $team = $side['team'];
                $picked = $team !== null && $pick?->picked_team_id === $team->id;
                $palette = $picked ? $team->palette() : null;
                $tappable = $interactive && ! $locked && $team !== null;
                $sideBurden = $burden === null
                    ? null
                    : ($slateGame->favorite_team_id === $team?->id ? '-'.$burden : '+'.$burden);
            @endphp

            <button
                type="button"
                wire:key="pick-side-{{ $slateGame->id }}-{{ $team?->id ?? $loop->index }}"
                @if ($tappable) wire:click="pick({{ $slateGame->id }}, {{ $team->id }})" @endif
                @disabled(! $tappable)
                @if ($picked) aria-pressed="true" @endif
                @style([
                    '--team-accent: '.$palette?->surface => $palette,
                    '--team-accent-contrast: '.$palette?->text => $palette,
                    '--team-keyline: '.$team?->altAccentColor() => $palette && $team?->altAccentColor(),
                ])
                @class([
                    'flex w-full items-center gap-2 rounded-lg border px-2.5 py-2 text-start transition-colors',
                    // The fill: TeamPalette's computed pairing, un-branded in
                    // dark mode by the utility itself — where the light
                    // border below is the selection signal instead.
                    'team-accent team-keyline border-black/10 dark:border-zinc-100' => $picked,
                    'border-zinc-200 dark:border-zinc-700' => ! $picked,
                    'hover:border-zinc-400 dark:hover:border-zinc-500' => $tappable && ! $picked,
                    'opacity-60' => $interactive && $locked && ! $picked && ! $graded,
                ])
            >
                {{-- A constant-size seat for the logo, pucked white only when
                     the side is filled — a one-color mark in its own team's
                     color would vanish into the surface. --}}
                <span @class([
                    'flex size-8 shrink-0 items-center justify-center rounded-full',
                    'bg-white shadow-sm ring-1 ring-black/10 dark:bg-transparent dark:shadow-none dark:ring-0' => $picked,
                ])>
                    <x-team-logo :team="$team" size="sm" />
                </span>

                {{-- The team-link grammar (rank · place · record), inlined so
                     the muted tones can ride the accent's own contrast color
                     instead of team-link's fixed grays. --}}
                <span class="flex min-w-0 flex-1 items-center gap-1.5">
                    @if ($side['rank'])
                        <span @class(['tabular shrink-0 text-micro font-semibold', 'opacity-75' => $picked, 'text-zinc-500' => ! $picked])>{{ $side['rank'] }}</span>
                    @endif

                    <span @class(['min-w-0 truncate text-sm', 'font-bold' => $picked, 'font-medium' => ! $picked])>
                        {{ $team?->placeName() ?? 'TBD' }}
                    </span>

                    @if ($side['record'])
                        <span @class(['tabular shrink-0 text-micro', 'opacity-70' => $picked, 'text-zinc-400' => ! $picked])>{{ $side['record'] }}</span>
                    @endif
                </span>

                <span class="flex shrink-0 items-center gap-1.5">
                    @if ($bearTeamId !== null && $team?->id === $bearTeamId)
                        {{-- The Bear's side, visible while you pick — his
                             cards are on the table by design. --}}
                        <span data-bear title="The Bear's pick" @class(['flex items-center', 'opacity-70' => $picked, 'text-red-500 dark:text-red-400' => ! $picked])>
                            <flux:icon.paw-print variant="micro" class="size-3.5" />
                            <span class="sr-only">The Bear's pick</span>
                        </span>
                    @endif

                    @if ($picked)
                        <flux:icon.check-circle-fill variant="micro" class="size-3.5" />
                    @endif

                    @if ($live || $final)
                        <span @class(['tabular text-micro', 'opacity-70' => $picked, 'text-zinc-400' => ! $picked])>{{ $sideBurden }}</span>
                        <span class="tabular w-6 text-right text-sm font-semibold tracking-tight">{{ $side['score'] }}</span>
                    @elseif ($sideBurden !== null)
                        <span class="tabular text-sm font-semibold tracking-tight">{{ $sideBurden }}</span>
                    @else
                        {{-- Null line means the book had nothing YET — a
                             draft-preview state, never a substituted number. --}}
                        <span class="text-micro font-medium {{ $picked ? 'opacity-70' : 'text-zinc-400' }}">Line pending</span>
                    @endif
                </span>
            </button>
        @endforeach
    </div>

    @if ($lockable)
        @php
            $bonus = App\Services\Contests\WoodshedMode::LOCK_BONUS;
            $penalty = App\Services\Contests\WoodshedMode::LOCK_PENALTY;
            $staked = (bool) $pick?->locked;
        @endphp

        <div class="flex items-center justify-between gap-2 border-t border-zinc-100 px-2.5 py-2 dark:border-zinc-800/60">
            @if ($interactive && ! $locked)
                {{-- The wager, stakes stated plainly: instructions never joke. --}}
                <button
                    type="button"
                    wire:click="lockPick({{ $slateGame->id }}, {{ $staked ? 'false' : 'true' }})"
                    @disabled($pick === null)
                    @if ($staked) aria-pressed="true" @endif
                    data-lock-toggle
                    @class([
                        'flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition-colors',
                        'border-red-900/40 bg-zinc-900 text-red-300 dark:border-red-950 dark:bg-black dark:text-red-400' => $staked,
                        'border-zinc-200 text-zinc-700 hover:border-zinc-400 dark:border-zinc-700 dark:text-zinc-300 dark:hover:border-zinc-500' => ! $staked && $pick !== null,
                        'border-zinc-200 text-zinc-400 dark:border-zinc-800' => $pick === null,
                    ])
                >
                    <flux:icon.hammer variant="micro" class="size-3.5" />
                    {{ $staked ? 'Locked in' : 'Lock it' }}
                </button>

                <span class="text-micro font-medium text-zinc-500">
                    @if ($pick === null)
                        Pick a side to stake the Lock.
                    @else
                        +{{ $bonus }} right · −{{ $penalty }} wrong
                    @endif
                </span>
            @elseif ($staked)
                {{-- Kicked with the wager riding: the state, said plainly. --}}
                <span data-lock-toggle class="flex items-center gap-1.5 text-xs font-semibold text-zinc-600 dark:text-zinc-300">
                    <flux:icon.hammer variant="micro" class="size-3.5" />
                    The Lock is riding · +{{ $bonus }} right, −{{ $penalty }} wrong
                </span>
            @endif
        </div>
    @endif
</div>
