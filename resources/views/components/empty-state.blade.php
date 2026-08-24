{{--
    An empty state with a DOOR. The class of bug this retires: a screen
    whose empty says "nothing here yet" to exactly the reader who needs to
    be told where "here" starts — the leaderboard and history empties were
    dead ends for the one person a lobby door would seat.

    flux:callout's shape (icon, heading, register-aware body), plus an
    action slot rendered through the callout's own actions area. The
    heading stays factual; the BODY is where Voice speaks.
--}}
@props(['icon' => 'user-group', 'heading', 'body' => null])

<flux:callout :icon="$icon">
    <flux:callout.heading>{{ $heading }}</flux:callout.heading>

    @if ($body)
        <flux:callout.text>{{ $body }}</flux:callout.text>
    @endif

    @if ($slot->isNotEmpty())
        <x-slot name="actions">
            {{ $slot }}
        </x-slot>
    @endif
</flux:callout>
