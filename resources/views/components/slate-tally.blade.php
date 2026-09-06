{{--
    THE RIGHT-HAND FIGURE on a card being played: where the reader stands,
    then what they have scored.

    One component for the three states that carry it (live, preliminary,
    final) rather than the same pair of spans written three times, which is
    how the points and the place would eventually come to be styled
    differently on a Saturday and a Sunday.

    `place` arrives as Placing's array or NULL, and null renders NOTHING —
    no place exists before a game kicks, in a field of one, or for a reader
    with no entry, and none of those is a "1st" waiting to be printed.
--}}
@props([
    /** @var array{place: int, field: int, tied: bool}|null */
    'place' => null,
    'points' => 0,
])

<span class="tabular flex min-w-0 shrink-0 items-baseline gap-1.5">
    @if ($place !== null)
        {{-- Muted, because the points are the figure the eye is coming for
             and the place is what they mean. --}}
        <span class="truncate font-semibold text-zinc-500 dark:text-zinc-400">{{ App\Support\Placing::label($place) }}</span>
        <span class="text-zinc-300 dark:text-zinc-600" aria-hidden="true">&middot;</span>
    @endif

    <span class="shrink-0 font-semibold">{{ $points }} pts</span>
</span>
