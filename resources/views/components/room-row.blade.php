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
    /** The HOST's join method, so the row can ride any screen that seats people. */
    'action' => 'joinLobby',
])

@php $palette = $mode->palette(); @endphp

<div {{ $attributes->class(['relative flex items-center gap-3 rounded-xl border border-zinc-200 px-3 py-2.5 transition-colors hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600']) }}>
    <a
        href="{{ route('pickem.room', $room) }}"
        wire:navigate
        class="absolute inset-0 z-0 rounded-xl"
        aria-label="{{ $room->name }} — {{ $mode->label() }}"
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

    <flux:button
        wire:click="{{ $action }}({{ $room->id }})"
        wire:loading.attr="disabled"
        wire:target="{{ $action }}({{ $room->id }})"
        size="sm"
        variant="primary"
        class="pointer-events-auto relative z-10 shrink-0"
    >Join</flux:button>
</div>
