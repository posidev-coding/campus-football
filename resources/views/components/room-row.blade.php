{{--
    ONE OPEN ROOM, as a row. A uniform ~64px line in a list of thirteen:
    the mode's tile, the room's name, one factual micro-line, and the
    door. The blurbs and zingers that used to ride each card live on the
    ROOM SCREEN now — a shopper scanning a shelf reads names, and thirteen
    pitches stacked is not a shelf, it is an essay.

    Mode identity is the TILE plus the micro-line, never a chip on the
    right: at 390px a chip and a button together starve the name, and
    nothing may be reachable only above `sm`.

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
    /** The HOST's join method, so the row can ride any screen that seats people. */
    'action' => 'joinLobby',
])

@php $palette = $mode->palette(); @endphp

<div {{ $attributes->class(['relative flex items-center gap-3 rounded-xl border border-zinc-200 px-3 py-2.5 transition-colors hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600']) }}>
    <a
        href="{{ route('pickem.room', $room) }}"
        wire:navigate
        class="absolute inset-0 z-0 rounded-xl"
        aria-label="{{ $room->name }} — {{ $mode->label() }}{{ $seated ? ' — view your picks' : '' }}"
    ></a>

    <span class="pointer-events-none relative z-10 flex size-8 shrink-0 items-center justify-center rounded-lg border {{ $palette['tile'] }}">
        <flux:icon :name="$mode->icon()" variant="micro" class="size-4 {{ $palette['icon'] }}" />
    </span>

    <span class="pointer-events-none relative z-10 min-w-0 flex-1">
        <span class="block truncate font-semibold leading-tight">{{ $room->name }}</span>
        <span class="tabular block truncate text-micro text-zinc-500 dark:text-zinc-400">
            {{ $mode->label() }}@if ($gameCount !== null) · {{ $gameCount }} {{ Str::plural('game', $gameCount) }}@endif@if ($room->member_cap !== null) · {{ $seats }} of {{ $room->member_cap }} seats @else · {{ $seats }} {{ Str::plural('member', $seats) }}@endif
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
    @else
        <flux:button
            wire:click="{{ $action }}({{ $room->id }})"
            wire:loading.attr="disabled"
            wire:target="{{ $action }}({{ $room->id }})"
            size="sm"
            variant="primary"
            class="pointer-events-auto relative z-10 -my-1 !h-9 shrink-0"
        >Join</flux:button>
    @endif
</div>
