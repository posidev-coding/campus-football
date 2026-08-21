{{--
    A public room in the lobby: the contest it plays — wearing that
    game's mark and colors from the identity seam — the seats left, and
    the door. Rooms wear NAMES ("Hail Mary", "Ranked Action II"), never
    dates or serials; the mode chip and the lobby's week context carry the
    boring facts. The blurb is the flavor's own one-line rules when the
    room has one, the mode enum's otherwise — either way the pitch can
    never drift from the rules cards. The zinger under a flavored blurb is
    Voice, three registers, optional by construction.

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
    /** @var App\Enums\LobbyFlavor|null a specialty room's identity */
    'flavor' => null,
    /** @var int|null this Saturday's slate size, for dynamic flavors */
    'gameCount' => null,
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

    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $flavor?->blurb() ?? $mode->blurb() }}</p>

    @if ($flavor?->dynamicSize() && $gameCount !== null)
        <p class="text-micro font-semibold text-zinc-500 dark:text-zinc-400">{{ $gameCount }} {{ Str::plural('game', $gameCount) }} this Saturday</p>
    @endif

    @if ($flavor !== null && ($zinger = App\Support\Voice::line($flavor->zingerKey(), ['conference' => $flavor->conferenceName() ?? ''])) !== '')
        <p class="text-micro italic text-zinc-400 dark:text-zinc-500">&ldquo;{{ $zinger }}&rdquo;</p>
    @endif

    <div class="flex items-center justify-between gap-3">
        @if ($room->member_cap !== null)
            <x-slate-progress :made="$seats" :total="$room->member_cap" />
        @else
            <span class="text-micro text-zinc-500">{{ $seats }} {{ Str::plural('seat', $seats) }} taken</span>
        @endif

        <flux:button wire:click="{{ $action }}({{ $room->id }})" size="sm" variant="primary" class="shrink-0">Join</flux:button>
    </div>
</div>
