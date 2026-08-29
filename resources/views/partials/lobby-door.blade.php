{{--
    THE LOBBY, as a door: a plain count and one line of Voice. The store
    lives at its own address — this is the sign above it, not a shelf of
    it.

    A PARTIAL because My Picks renders it in two places that are mutually
    exclusive: at the foot of the screen for a reader with groups, and
    hoisted up beside the mode doors on a first run, where the two ways to
    play have to sit next to each other. Same markup and, more to the
    point, the same SINGLE `roomsOpen` read — the count is a lean COUNT
    and LobbyRoomsTest pins it against the store's own list, so a second
    call here would be a second full question asked of the same answer.

    "public rooms", not "rooms": the word is the entire distinction this
    screen is now drawing, and the door to the store is where a reader
    who has never seen the store reads it.
--}}
@php $teaser = App\Support\Voice::line('lobby.teaser.zinger'); @endphp

<x-link-row :href="route('pickem.lobby')" title="The Lobby" data-tour="room">
    <span class="block pt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
        @if ($this->roomsOpen > 0)
            {{ $this->roomsOpen }} public {{ Str::plural('room', $this->roomsOpen) }} open this Saturday
        @else
            {{ App\Support\Voice::line('lobby.publics.empty') }}
        @endif
    </span>
    @if ($this->roomsOpen > 0 && $teaser !== '')
        <span class="text-micro block pt-0.5 text-zinc-500 dark:text-zinc-400">{{ $teaser }}</span>
    @endif
</x-link-row>
