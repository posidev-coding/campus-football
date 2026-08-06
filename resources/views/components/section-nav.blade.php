@php
    $sections = App\Support\Navigation::currentSections();
@endphp

{{--
    Sections WITHIN the current area, not the whole app.

    This used to list all nine sections regardless of where you were, which made
    it a second copy of the bottom bar rather than a level below it. Now League
    shows Standings · Rankings · Teams · Players · Stats · Recruiting, and
    single-screen areas — Home and Scores — show nothing at all: "when
    necessary" rather than always.

    The strip speaks the CHIP language of `x-area-nav` one level up — all
    navigation is chips and color, and the underline is exclusively x-plate's
    in-content control idiom. That is what keeps the levels apart: a reader
    never has to ask whether an underlined row navigates or filters.

    The active chip classes are therefore shared with the area nav's current
    tab, which is in the DOM (md:flex-hidden) on every League page — so a test
    must scope to `aria-label="Sections"` rather than count the string
    page-wide.

    Some overlap with the tab bar is deliberate: an area's landing route appears
    as its own first section, because it is both where the area starts and
    somewhere you navigate back to.
--}}
@if (count($sections) > 1)
    <nav
        {{-- Keyed on `aria-current`, not a parallel data attribute: one
             source of truth for "this is the current section", and the
             semantic one, which assistive tech reads as well. --}}
        x-data="{
            center() {
                this.$refs.strip?.querySelector('[aria-current=page]')
                    ?.scrollIntoView({ block: 'nearest', inline: 'center' })
            },
        }"
        x-init="$nextTick(() => center())"
        {{ $attributes->class(['border-t border-zinc-200 sm:border-t-0 dark:border-zinc-800']) }}
        aria-label="Sections"
    >
        <div
            x-ref="strip"
            class="flex gap-1 overflow-x-auto px-4 py-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        >
            @foreach ($sections as $section)
                {{-- A section lights on its detail pages too — a team page
                     keeps the Teams chip filled — via the same routes-list
                     idea the area tabs use. --}}
                @php $current = request()->routeIs(...($section['routes'] ?? [$section['route']])); @endphp

                <a
                    href="{{ route($section['route']) }}"
                    wire:navigate
                    @if ($current) aria-current="page" @endif
                    @class([
                        'shrink-0 rounded-md px-2.5 py-1.5 text-sm font-medium whitespace-nowrap transition-colors',
                        'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100' => $current,
                        'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-zinc-100' => ! $current,
                    ])
                >{{ $section['label'] }}</a>
            @endforeach
        </div>
    </nav>
@endif
