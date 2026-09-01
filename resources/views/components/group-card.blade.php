{{--
    ONE OF YOUR CONTESTS — a group or a joined room as a
    whole-card door to its clubhouse, wearing its mode's mark and colors
    (the identity seam) with the week's state on the second row.

    `card` is the lobby's cards() array shape: group, contest,
    commissioner, past, state (waiting | upcoming | live | prelim |
    final), made/total, entryIn, points, won, wins, firstKick, deadline.
    The five-way state row moved here wholesale from pass 2's inline
    markup — same states, same words, now beside an identity a thumb can
    find in a stack.

    The micro-line is FACTS since 2026-09-01: the kind moved back onto
    the section headings ("My Groups" / "Week N Contests", the same two
    the group switcher shows), so the card says how many members and
    whether the reader runs it. An evergreen alone keeps "Always open ·"
    in front, because its section names a Saturday and a table is not
    one. Same props: `group`, `commissioner` and `past` were already here.
--}}
@props([
    /** @var array<string, mixed> */
    'card',
])

@php
    $group = $card['group'];
    $mode = $card['contest']?->mode;
    $palette = $mode?->palette();
    $members = $group->memberships_count ?? null;

    // Facts, plain in every register. Null means no data and is skipped,
    // never a zero standing in for it.
    $facts = collect([
        $group->isLobby() && ! $group->isRoom() ? 'Always open' : null,
        $members === null ? null : $members.' '.Str::plural('member', $members),
        ($card['commissioner'] ?? false) ? "you're the commissioner" : null,
    ])->filter()->implode(' · ');
@endphp

<a
    href="{{ $group->isRoom() ? route('pickem.room', $group) : route('pickem.group', $group) }}"
    wire:navigate
    {{ $attributes->class(['flex flex-col gap-2 rounded-xl border border-zinc-200 px-4 py-3 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600']) }}
>
    <div class="flex items-center justify-between gap-3">
        <span class="flex min-w-0 items-center gap-2.5">
            @if ($mode !== null)
                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg border {{ $palette['tile'] }}">
                    <flux:icon :name="$mode->icon()" variant="micro" class="size-4 {{ $palette['icon'] }}" />
                </span>
            @endif

            <span class="min-w-0">
                <span class="block truncate font-semibold leading-tight">{{ $group->name }}</span>
                {{-- THE FACTS. The kind lives on the section heading now;
                     an evergreen alone keeps "Always open" here, never a
                     room's one-Saturday label and never "table" — two
                     user-facing container nouns, still. --}}
                @if ($facts !== '')
                    <span class="block truncate text-micro text-zinc-500">{{ $facts }}</span>
                @endif
            </span>
        </span>

        <span class="flex shrink-0 items-center gap-1.5">
            @if ($card['wins'] > 0)
                <flux:badge size="sm" color="green">{{ $card['wins'] }} {{ Str::plural('win', $card['wins']) }}</flux:badge>
            @endif
            @if ($mode !== null)
                <span class="rounded-full px-2 py-0.5 text-micro font-semibold {{ $palette['chip'] }}">{{ $mode->label() }}</span>
            @endif
        </span>
    </div>

    <div class="flex items-center justify-between gap-3 text-sm">
        @if ($card['state'] === 'waiting')
            {{-- A PUBLIC ROOM WHOSE SATURDAY IS GONE. It has no slate on
                 the card, so it falls through the state match to
                 'waiting' — and the waiting line names a commissioner the
                 room never had, on a week that is never coming.

                 My Picks no longer STACKS a played room (a room is a
                 transient contest; it leaves the stack for the History
                 door), so this branch is the card's own guard rather
                 than a display path: whoever renders a past card next
                 inherits the right words instead of the lie. --}}
            @if ($card['past'] ?? false)
                <span class="min-w-0 truncate text-zinc-500 dark:text-zinc-400">{{ App\Support\Voice::line('group.room.past') }}</span>
            @elseif ($group->isRoom())
                {{-- A ROOM WITH NO CARD, on a week that has not gone by:
                     its slate never landed, or was taken away. There is
                     nobody to go rattle — the house runs these rooms and
                     no copy inside one may say "your commissioner" — so
                     this states the room's condition and points at the
                     Lobby, where the rooms with games in them are. --}}
                <span class="min-w-0 truncate text-zinc-500 dark:text-zinc-400">{{ App\Support\Voice::line('group.room.no_card') }}</span>
            @elseif ($card['commissioner'] && $card['contest'] && ! $card['buildable'])
                {{-- A Saturday too thin to seat this mode. The blue call
                     to action would send the commissioner to a wizard
                     whose publish can only refuse, and the deadline
                     beside it would be a clock on work nobody can do —
                     so the card states the condition instead, in the
                     lobby's own words for it. The clubhouse says which
                     numbers and when. --}}
                <span class="min-w-0 truncate text-zinc-500 dark:text-zinc-400">Not enough games this Saturday</span>
            @elseif ($card['commissioner'] && $card['contest'])
                <span class="font-medium text-blue-600 dark:text-blue-400">Build the slate</span>
                @if ($card['deadline'])
                    <span class="shrink-0 text-micro text-zinc-500">due {{ $card['deadline']->format('D g:ia') }}</span>
                @endif
            @else
                <span class="min-w-0 truncate text-zinc-500 dark:text-zinc-400">{{ App\Support\Voice::line('group.slate.waiting') }}</span>
            @endif
        @elseif ($card['state'] === 'upcoming')
            {{-- A finished entry is not 15 of 15 — it is DONE, and the
                 card says the word rather than making a reader read a
                 fraction and do the comparison. The kick time stays:
                 knowing you are in does not stop you wanting to know
                 when it starts. --}}
            @if ($card['entryIn'])
                <span class="flex min-w-0 items-center gap-1.5 font-medium text-emerald-700 dark:text-emerald-400">
                    <flux:icon.check-circle-fill variant="micro" class="size-3.5 shrink-0" />
                    Entry in
                </span>
            @elseif ($card['total'] > 0 && $card['made'] >= $card['total'])
                {{-- Every game picked, the week's question still open —
                     the same amber the pick surface's sticky slot wears. --}}
                <span class="min-w-0 truncate font-medium text-amber-600 dark:text-amber-400">Tiebreaker left</span>
            @else
                <x-slate-progress :made="$card['made']" :total="$card['total']" />
            @endif
            @if ($card['firstKick'])
                <span class="shrink-0 text-micro text-zinc-500">
                    kicks {{ $card['firstKick']->setTimezone(config('cfb.timezone'))->format('D g:ia') }}
                </span>
            @endif
        @elseif ($card['state'] === 'live')
            <x-slate-status status="live" />
            <span class="tabular shrink-0 font-semibold">{{ $card['points'] }} pts</span>
        @elseif ($card['state'] === 'prelim')
            <x-slate-status status="prelim" />
            <span class="tabular shrink-0 font-semibold">{{ $card['points'] }} pts</span>
        @else
            <span class="flex items-center gap-1.5">
                <x-slate-status status="final" />
                @if ($card['won'])
                    <flux:badge size="sm" color="green">Winner</flux:badge>
                @endif
            </span>
            <span class="tabular shrink-0 font-semibold">{{ $card['points'] }} pts</span>
        @endif
    </div>
</a>
