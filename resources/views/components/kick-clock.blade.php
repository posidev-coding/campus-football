{{--
    TIME UNTIL THE FIRST KICKOFF — the countdown idiom's one home.

    Client-driven for the reason the pick surface's ring is: the screen
    only re-renders on interaction, and a countdown that never moved would
    not be one. Days read as WORDS ("kicks Sat 3:30pm") because a reader
    three days out is choosing a weekend, not watching a clock; the final
    hour reads as mm:ss, which is the shape that says hurry.

    THE SERVER RENDERS THE SAME STRING as static initial content, so the
    element is right before Alpine boots, right for a reader with no JS at
    all, and — the part that matters to the suite — assertable end-state
    DOM. The automated tab produces no rendering frames, so a test pins
    `data-kick-at` and the static string and NEVER a tick.

    No `$wire.$refresh()` at zero: this clock rides cards that are read,
    not surfaces that lock. A stale "Kickoff" is exactly what the static
    span it replaces would have said. And no countdown-ring — that idiom
    stays pinned to its own component, on the surface where the ring means
    picks are about to close.

    `at` has NO default. A caller with no kickoff to show SKIPS the
    component; null means no data, and a clock counting down to the epoch
    is worse than no clock.
--}}
@props([
    /** @var \Carbon\CarbonInterface the kickoff to count toward */
    'at',
    /** Idle-state lead-in: "kicks Sat 3:30pm" / "First kick Sat 3:30pm". */
    'idlePrefix' => 'kicks',
    /** Final-hour trailer, after the mm:ss. */
    'suffix' => 'to kickoff',
])

@php
    $seconds = $at->getTimestamp() - now()->getTimestamp();

    // The idle words are built HERE and handed to Alpine, rather than
    // rebuilt in the browser: one string, one timezone, one source. A
    // client re-deriving "Sat 3:30pm" from a timestamp is a second
    // formatter that can disagree with the server about the same kickoff.
    $idle = $idlePrefix.' '.$at->copy()->setTimezone(config('cfb.timezone'))->format('D g:ia');

    $initial = match (true) {
        $seconds <= 0 => 'Kickoff',
        $seconds >= 3600 => $idle,
        default => intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).' '.$suffix,
    };
@endphp

<span
    data-kick-at="{{ $at->getTimestamp() }}"
    {{ $attributes->class(['tabular']) }}
    x-data="{
        remaining: @js($seconds),
        idle: @js($idle),
        suffix: @js($suffix),
        timer: null,
        start() {
            if (this.remaining <= 0) return;

            this.timer = setInterval(() => {
                this.remaining = Math.max(0, this.remaining - 1);

                if (this.remaining === 0) {
                    this.stop();
                }
            }, 1000);
        },
        stop() {
            if (this.timer) clearInterval(this.timer);
            this.timer = null;
        },
        label() {
            const s = this.remaining;
            if (s <= 0) return 'Kickoff';
            if (s >= 3600) return this.idle;

            return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0') + ' ' + this.suffix;
        },
    }"
    x-init="start()"
    x-on:beforeunload.window="stop()"
    x-text="label()"
>{{ $initial }}</span>
