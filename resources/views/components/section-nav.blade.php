@php
    $sections = App\Support\Navigation::currentSections();
@endphp

{{--
    Sections WITHIN the current area, not the whole app.

    This used to list all nine sections regardless of where you were, which made
    it a second copy of the bottom bar rather than a level below it. Now Scores
    shows Scores · Bowls, League shows Standings · Rankings · Teams · Stats ·
    Leaders · Recruiting, and single-screen areas show nothing at all — "when
    necessary" rather than always.

    Some overlap with the tab bar is deliberate: an area's landing route appears
    as its own first section, because it is both where the area starts and
    somewhere you navigate back to.
--}}
@if (count($sections) > 1)
    <nav
        x-data="{
            center() {
                this.$refs.strip?.querySelector('[data-current=true]')
                    ?.scrollIntoView({ block: 'nearest', inline: 'center' })
            },
        }"
        x-init="$nextTick(() => center())"
        {{ $attributes->class(['border-t border-zinc-200 sm:border-t-0 dark:border-zinc-800']) }}
        aria-label="Sections"
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
@endif
