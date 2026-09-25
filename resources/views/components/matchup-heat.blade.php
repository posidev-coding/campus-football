@props(['score'])

{{--
    A game card's heat ring: how much of the circle is filled is the
    MatchupHeat score, its color is the tier, and only the two ends of the
    scale carry an icon — a flame for fire, a moon for a snooze. The middle
    tiers are the ring alone, so the icons stay rare enough to mean something.

    Drawn static, like the matchup donut and for the same reason: an entrance
    animation that stalls leaves an empty ring, and the automated tab never
    produces the frames to finish one.
--}}
@php
    $heat = App\Enums\MatchupHeat::fromScore($score);

    $radius = 15;
    $stroke = 3;
    $circumference = 2 * M_PI * $radius;

    /*
     * Round caps add half the stroke at EACH end, so the dash is drawn one
     * stroke short and the visible arc lands on the score. Anything under a
     * stroke's worth becomes a zero dash, which the caps still draw as a dot
     * — a 2 reads as "barely", not as an empty ring.
     */
    $arc = max(0, $circumference * min(100, max(0, $score)) / 100 - $stroke);

    [$ring, $icon] = match ($heat) {
        App\Enums\MatchupHeat::Fire => ['stroke-orange-500 dark:stroke-orange-400', 'text-orange-500 dark:text-orange-400'],
        App\Enums\MatchupHeat::Warm => ['stroke-amber-400 dark:stroke-amber-500', null],
        App\Enums\MatchupHeat::Mild => ['stroke-zinc-400 dark:stroke-zinc-500', null],
        App\Enums\MatchupHeat::Snooze => ['stroke-indigo-300 dark:stroke-indigo-400/70', 'text-indigo-400 dark:text-indigo-300'],
    };
@endphp

<span
    {{ $attributes->class(['relative grid size-9 shrink-0 place-items-center']) }}
    role="img"
    aria-label="{{ $heat->label() }}, {{ $score }} of 100"
    data-heat="{{ $heat->value }}"
>
    <svg viewBox="0 0 36 36" class="absolute inset-0 size-full -rotate-90" aria-hidden="true">
        <circle cx="18" cy="18" r="{{ $radius }}" fill="none" stroke-width="{{ $stroke }}"
                class="stroke-zinc-200/70 dark:stroke-zinc-800" />
        <circle cx="18" cy="18" r="{{ $radius }}" fill="none" stroke-width="{{ $stroke }}" stroke-linecap="round"
                class="{{ $ring }}"
                stroke-dasharray="{{ round($arc, 2) }} {{ round($circumference, 2) }}" />
    </svg>

    @if ($heat === App\Enums\MatchupHeat::Fire)
        <flux:icon.fire class="relative size-4 {{ $icon }}" />
    @elseif ($heat === App\Enums\MatchupHeat::Snooze)
        <flux:icon.moon-stars-fill class="relative size-3.5 {{ $icon }}" />
    @endif
</span>
