{{--
    The emptying ring — time you still HAVE, running out. Alpine-driven:
    `show` and `fraction` are expressions over the caller's own x-data
    (remaining, total…), evaluated client-side because these surfaces only
    re-render on interaction and a countdown that never moved would not be
    one. 56.55 is the r=9 circumference; the transition is stripped under
    reduced motion by the utility itself.
--}}
@props([
    /** Alpine expression: when the ring is visible at all. */
    'show' => 'remaining > 0',
    /** Alpine expression for elapsed 0..1 — e.g. "remaining / 3600". */
    'fraction',
    'strokeWidth' => '3',
])

<svg
    x-show="{{ $show }}"
    x-cloak
    viewBox="0 0 24 24"
    {{ $attributes->class(['size-4 -rotate-90']) }}
    aria-hidden="true"
>
    <circle cx="12" cy="12" r="9" fill="none" stroke-width="{{ $strokeWidth }}"
            class="stroke-zinc-200 dark:stroke-zinc-700" />
    <circle cx="12" cy="12" r="9" fill="none" stroke-width="{{ $strokeWidth }}" stroke-linecap="round"
            class="stroke-blue-500 transition-[stroke-dashoffset] duration-1000 ease-linear motion-reduce:transition-none"
            stroke-dasharray="56.55"
            :style="`stroke-dashoffset: ${56.55 * (1 - ({{ $fraction }}))}`"
    />
</svg>
