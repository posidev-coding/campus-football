{{--
    A public room on the lobby floor: the game it plays — wearing that
    game's mark and colors from the identity seam — the seats left, and
    the door. The name already says everything deterministic ("Triple
    Option Open · Sep 12 · Room 2" — the SATURDAY, not the ESPN week, so
    two cards inside one split week cannot share a name); the blurb is the
    enum's own one-line rules so the pitch can never drift from the mode
    cards.

    The seats meter reuses x-slate-progress's grammar — a thin bar plus
    the number a joiner actually reads. `action` names the HOST's join
    method so the card can ride any screen that seats people.
--}}
@props([
    'room',
    /** @var App\Enums\ContestMode */
    'mode',
    'seats' => 0,
    'action' => 'joinLobby',
])

@php $palette = $mode->palette(); @endphp

<div {{ $attributes->class(['flex flex-col gap-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700']) }}>
    <div class="flex items-center justify-between gap-3">
        <span class="flex min-w-0 items-center gap-2.5">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg border {{ $palette['tile'] }}">
                <flux:icon :name="$mode->icon()" variant="micro" class="size-4 {{ $palette['icon'] }}" />
            </span>
            <span class="min-w-0 truncate font-semibold">{{ $room->name }}</span>
        </span>

        <span class="shrink-0 rounded-full px-2 py-0.5 text-micro font-semibold {{ $palette['chip'] }}">{{ $mode->label() }}</span>
    </div>

    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $mode->blurb() }}</p>

    <div class="flex items-center justify-between gap-3">
        @if ($room->member_cap !== null)
            <x-slate-progress :made="$seats" :total="$room->member_cap" />
        @else
            <span class="text-micro text-zinc-500">{{ $seats }} {{ Str::plural('seat', $seats) }} taken</span>
        @endif

        <flux:button wire:click="{{ $action }}({{ $room->id }})" size="sm" variant="primary" class="shrink-0">Join</flux:button>
    </div>
</div>
