@props([
    /** array<string, string> value => label. */
    'tabs' => [],
    'selected' => '',
    'model' => 'tab',
    /** Per-screen wire:key prefix. */
    'keyPrefix' => 'teamnav',
    /** Accessible name for the nav. */
    'label' => 'Team page section',
])

@php
    /*
     * The TEAM NAV — the sub nav for a hero-led team screen, modelled on how
     * ESPN sits a team's sections directly under its identity block.
     *
     * Three things make it, and all three are the point:
     *
     *   EDGE TO EDGE   `-mx-4 px-4` cancels the container's padding so the
     *                  rule reaches both edges of the viewport while the
     *                  labels still line up with the content below. The hero
     *                  above bleeds the same way, so they read as one stack.
     *   FLUSH          `-mt-5` cancels the page container's `gap-5`, netting
     *                  the space above to zero. Tucked against the hero's
     *                  keyline rather than floating below it.
     *   RULED          one `border-b` the full width, with the active tab's
     *                  `border-b-2` resting directly ON it — which is what
     *                  makes the row one object instead of tabs above a line.
     *
     * WHY NOT x-plate, which already has all of that: the plate THROWS past
     * three tabs and this is five. Its shape is deliberate — a plate is a fork
     * in a screen, not a menu of sections. So this is its own component rather
     * than a loosened plate, and `ChromeConsistencyTest` allowlists exactly
     * these two files for `border-b-2`.
     *
     * The underline is NEUTRAL, not the team's color. `--team-accent` was the
     * obvious choice and is wrong on real data: the ladder's rung 1 leaves a
     * LIGHT surface behind dark text (Colorado's gold is 1.6:1 against the
     * page), so a team-colored rule would be invisible for those schools and
     * would need a whole second contrast ladder to be safe. The hero directly
     * above already carries the brand; this row only has to say which section
     * you are in.
     *
     * Weight does not change between states either — color and the underline
     * carry it. Bolding the active tab reflows the row on every switch, so the
     * labels visibly shift as you move along them.
     */
@endphp

<nav
    {{ $attributes->class(['-mx-4 -mt-5 border-b border-zinc-200 px-4 dark:border-zinc-800']) }}
    aria-label="{{ $label }}"
>
    {{-- No horizontal scroll: this is chrome, and the no-scroll rule has three
         sanctioned exceptions this is not joining. Five labels plus their gaps
         measure well inside 390 — see the numbers on the caller — so a sixth
         tab or a longer word has to be measured before it ships. --}}
    <div class="flex gap-5 sm:gap-7">
        @foreach ($tabs as $value => $text)
            @php $active = $selected === $value; @endphp

            {{-- `pb-2.5` and the 2px border together make the bottom edge, so
                 the underline lands exactly on the container's rule. --}}
            <button
                type="button"
                wire:click="$set('{{ $model }}', '{{ $value }}')"
                wire:key="{{ $keyPrefix }}-{{ $value }}"
                @if ($active) aria-current="page" @endif
                @class([
                    'shrink-0 border-b-2 pt-3 pb-2.5 text-sm font-medium whitespace-nowrap transition-colors',
                    'border-zinc-900 text-zinc-900 dark:border-zinc-100 dark:text-zinc-100' => $active,
                    'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => ! $active,
                ])
            >{{ $text }}</button>
        @endforeach
    </div>
</nav>
