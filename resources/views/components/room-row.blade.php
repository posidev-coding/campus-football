{{--
    ONE OPEN ROOM, as a row. A uniform line in a list of thirteen: the
    mode's tile, the room's name, ONE truncating pitch line, one factual
    micro-line, and the door.

    THE PITCH LINE IS A REVERSAL, made deliberately (2026-08-31). The
    blurbs moved to the room screen because thirteen stacked pitches is an
    essay, not a shelf — and that was right about PARAGRAPHS and wrong
    about the shelf: ten flavored rooms shipped with ten personalities and
    the store rendered none of them, so "Upset Alley" and "Two-Minute
    Drill" were two names over identical rows. The line is capped at ONE
    and TRUNCATES, which is what keeps the reversal a shelf: uniform rows,
    each with one sentence of its own.

    Mode identity is the TILE plus the micro-line, never a chip on the
    right: at 390px a chip and a button together starve the name, and
    nothing may be reachable only above `sm`. THE PRICE rides that same
    micro-line for the same reason, and it leads it — a Tallboy is the one
    fact on the row that costs the reader something, so it must survive the
    truncation that a seat count does not have to.

    The price is a FACT and says the same words in every register; the
    shelf's Voice line above carries the slang. The button is the one place
    the canonical verb appears, because a button reading "Crush" over rules
    text saying "ice down" reads as two different features — and the plain
    instruction sits immediately beside it, so the verb never has to carry
    "this joins the room" on its own.

    SEATS LEFT is said in WEIGHT, never in color. Rows repeat, the amber
    budget is one per viewport, and the verify callout above may already
    be holding it — thirteen amber rows is a store shouting at itself.
    Weight also survives dark mode, which un-brands.

    Stretched anchor, the game-card grammar: the whole row opens the room
    (a read-only slate and a join door for non-members), while Join stays
    a one-tap button. A button may not nest inside an anchor, so the
    anchor is an absolutely-positioned sibling under the contents and the
    button lifts back above it with `pointer-events-auto`.
--}}
@props([
    'room',
    /** @var App\Enums\ContestMode */
    'mode',
    /** @var int|null games on this room's published slate */
    'gameCount' => null,
    'seats' => 0,
    /** Whether the VIEWER already holds a seat here — the row's door changes. */
    'seated' => false,
    /**
     * Whether this room's card has KICKED. The row still reads in full — the
     * name, the pitch, the seats, and a tap through to the slate — because a
     * reader deciding where to play is served by seeing what is already
     * running. Only the door changes.
     */
    'locked' => false,
    /** The HOST's join method, so the row can ride any screen that seats people. */
    'action' => 'joinLobby',
    /**
     * ONE line of what this room IS. Null renders nothing — an evergreen
     * has no Saturday to pitch, and a row with no pitch is a row, not a
     * hole.
     */
    'pitch' => null,
])

@php
    $palette = $mode->palette();

    /*
     * What this seat costs, read off data already loaded: the flavor names
     * a shelf and the shelf owns the price. Zero for every free shelf, and
     * zero renders nothing — a row with no price is a row, not a hole.
     */
    $price = $room->entryCredits();

    /*
     * How many seats are actually left. Null cap means an uncapped room —
     * no number to count down, so no urgency to claim. The signal is for
     * somebody who could still take one, so a reader already seated never
     * sees it — and neither does one looking at a room whose card has
     * kicked, where two seats left is not scarcity, it is furniture.
     */
    $left = $room->member_cap === null ? null : max(0, $room->member_cap - $seats);
    $scarce = $left !== null && $left <= 2 && ! $seated && ! $locked;
@endphp

<div {{ $attributes->class(['relative flex items-center gap-3 rounded-xl border border-zinc-200 px-3 py-2.5 transition-colors hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600']) }}>
    <a
        href="{{ route('pickem.room', $room) }}"
        wire:navigate
        class="absolute inset-0 z-0 rounded-xl"
        aria-label="{{ $room->name }} — {{ $mode->label() }}{{ $seated ? ' — view your picks' : ($locked ? ' — kicked off' : '') }}"
    ></a>

    @if ($room->conferenceLogoUrl() !== null)
        {{-- A conference room wears its conference — the shield ESPN synced,
             through x-group-icon; the micro-line below still names the mode. --}}
        <x-group-icon :group="$room" shape="rounded-lg" class="pointer-events-none relative z-10 size-8 text-micro" />
    @else
        <span class="pointer-events-none relative z-10 flex size-8 shrink-0 items-center justify-center rounded-lg border {{ $palette['tile'] }}">
            <flux:icon :name="$mode->icon()" variant="micro" class="size-4 {{ $palette['icon'] }}" />
        </span>
    @endif

    <span class="pointer-events-none relative z-10 min-w-0 flex-1">
        <span class="block truncate font-semibold leading-tight">{{ $room->name }}</span>
        @if ($pitch !== null)
            <span class="block truncate text-micro text-zinc-500 dark:text-zinc-400">{{ $pitch }}</span>
        @endif
        <span class="tabular block truncate text-micro text-zinc-500 dark:text-zinc-400">
            {{-- THE LAST SEATS, in weight. A fact, not a flourish: it
                 stays the same sentence in every register, and it only
                 speaks to somebody who could still take one. --}}
            {{ $mode->label() }}@if ($price > 0) · <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $price }} {{ Str::plural('Tallboy', $price) }} to enter</span>@endif@if ($gameCount !== null) · {{ $gameCount }} {{ Str::plural('game', $gameCount) }}@endif@if ($scarce) · <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $left }} {{ Str::plural('seat', $left) }} left</span>@elseif ($room->member_cap !== null) · {{ $seats }} of {{ $room->member_cap }} seats @else · {{ $seats }} {{ Str::plural('member', $seats) }}@endif
        </span>
    </span>

    {{-- A SEAT IS NOT A SALE. Offering Join to somebody already sitting
         in the room is a CTA for something they have done — and the tap
         would only re-answer with a membership they hold. The row still
         goes exactly where they want (their picks), so the door drops to
         a flat cue and lets the stretched anchor carry the tap. --}}
    @if ($seated)
        <span class="pointer-events-none relative z-10 flex shrink-0 items-center gap-0.5 text-sm font-medium text-zinc-500 dark:text-zinc-400">
            View picks
            <flux:icon name="chevron-right" variant="micro" class="size-4" />
        </span>
    {{-- THE BALL IS IN THE AIR, so the seat is gone but the room is not.
         The same flat cue the seated row uses, for the same reason: a
         disabled Join is a control that looks like it might work, and a row
         that simply vanished mid-afternoon reads as a bug rather than as a
         contest under way. The stretched anchor still carries the tap
         through to the slate.

         "Kicked off" is the game's own word for the thing that shut this
         door, and it is the word the code uses too — Game::hasKickedOff(),
         Slate::firstKickoff(), and the clock above this shelf counting down
         "to first kick". One vocabulary from the guard to the label. It says
         the same words in every register, like the price: a door is an
         affordance, and the shelf's Voice line above carries the slang. --}}
    @elseif ($locked)
        <span class="pointer-events-none relative z-10 flex shrink-0 items-center gap-1 text-sm font-medium text-zinc-500 dark:text-zinc-400">
            <flux:icon name="lock-closed" variant="micro" class="size-4" />
            Kicked off
        </span>
    @else
        <flux:button
            wire:click="{{ $action }}({{ $room->id }})"
            wire:loading.attr="disabled"
            wire:target="{{ $action }}({{ $room->id }})"
            size="sm"
            variant="primary"
            class="pointer-events-auto relative z-10 -my-1 !h-9 shrink-0"
            aria-label="{{ $price > 0 ? 'Ice down a Tallboy and join '.$room->name : 'Join '.$room->name }}"
        >{{ $price > 0 ? 'Ice down' : 'Join' }}</flux:button>
    @endif
</div>
