@php
    /*
     * The secondary navigation, ESPN-style: a horizontally scrolling strip of
     * sections with the current one underlined.
     *
     * This exists because the app now has nine public sections and the header
     * could carry four. A scrolling strip is the standard sports-app answer —
     * it keeps every section one tap away instead of burying most of them in an
     * overflow menu.
     *
     * The bottom nav deliberately does NOT do this. A strip you have to swipe is
     * the wrong pattern for a thumb, so that stays four fixed destinations.
     */
    $sections = [
        ['route' => 'scoreboard', 'label' => 'Scores'],
        ['route' => 'news', 'label' => 'News'],
        ['route' => 'rankings', 'label' => 'Rankings'],
        ['route' => 'standings', 'label' => 'Standings'],
        ['route' => 'stats', 'label' => 'Stats'],
        ['route' => 'leaders', 'label' => 'Leaders'],
        ['route' => 'teams', 'label' => 'Teams'],
        ['route' => 'bowls', 'label' => 'Bowls'],
        ['route' => 'recruiting', 'label' => 'Recruiting'],
    ];
@endphp

<nav
    x-data="{
        center() {
            this.$refs.strip?.querySelector('[data-current=true]')
                ?.scrollIntoView({ block: 'nearest', inline: 'center' })
        },
    }"
    x-init="$nextTick(() => center())"
    {{ $attributes->class(['border-b border-zinc-200 dark:border-zinc-800']) }}
>
    <div
        x-ref="strip"
        class="flex gap-1 overflow-x-auto px-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    >
        @foreach ($sections as $section)
            @php $current = request()->routeIs($section['route']); @endphp

            <a
                href="{{ route($section['route']) }}"
                wire:navigate
                data-current="{{ $current ? 'true' : 'false' }}"
                @class([
                    'shrink-0 border-b-2 px-2 py-2.5 text-sm font-medium whitespace-nowrap transition-colors',
                    'border-zinc-900 text-zinc-900 dark:border-zinc-100 dark:text-zinc-100' => $current,
                    'border-transparent text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100' => ! $current,
                ])
            >{{ $section['label'] }}</a>
        @endforeach
    </div>
</nav>
