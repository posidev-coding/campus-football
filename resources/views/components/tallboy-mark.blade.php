{{--
    THE CURRENCY'S ART, in one file.

    Extracted from x-wallet-chips when the wager gave the mark a second
    render site: the seam only holds if there is exactly ONE place that
    knows what a Tallboy looks like. If App Store review ever reads the can
    as alcohol imagery (roadmap Phase 7 carries the contingency), the swap —
    art, or a per-user variant — happens here and nowhere else.

    Always the CHIP CUT: every render site is small, and below 24px the
    reflection, base rim and range are mud (public/brand/currency/README.md).
    Light and dark are swapped the way x-team-logo swaps its marks. Both
    files declare width/height as well as a viewBox, without which an <img>
    letterboxes the can into 42% of a square box — TallboyMarkTest pins it.
--}}
@props([
    /** Rendered HEIGHT. The width follows the can's own 42:100 ratio. */
    'size' => 18,
])

<img src="{{ asset('brand/currency/svg/tallboy-light-16.svg') }}" alt="" {{ $attributes->class(['w-auto shrink-0 dark:hidden'])->merge(['style' => 'height: '.$size.'px']) }}>
<img src="{{ asset('brand/currency/svg/tallboy-dark-16.svg') }}" alt="" {{ $attributes->class(['hidden w-auto shrink-0 dark:block'])->merge(['style' => 'height: '.$size.'px']) }}>
