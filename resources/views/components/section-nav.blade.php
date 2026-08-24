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

    Below `lg` the strip speaks the CHIP language of `x-area-nav` one level up
    — all navigation is chips and color, and the underline is exclusively
    x-plate's in-content control idiom, so a reader never has to ask whether
    an underlined row navigates or filters.

    From `lg` the same strip restyles as an UNDERLINED TAB ROW, the classic
    sports-desktop second header row. Two chip rows stacked in one header read
    as one nav wrapped onto two lines; the underline is what makes the section
    level a different species from the area chips beside the brand. The
    in-content rule survives because this row lives in the HEADER: chrome may
    wear the underline at `lg`, a control inside content still may not —
    ChromeConsistencyTest allowlists exactly this file for it.

    The active chip classes are shared with the area nav's current tab, which
    is in the DOM (sm:flex-hidden) on every League page — so a test must scope
    to `aria-label="Sections"` rather than count the string page-wide.

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
        {{-- No top rule. Below `sm` this strip IS the header — the brand row
             above it is `sm:flex` — so a `border-t` drew a 1px line across the
             very top of the screen with nothing above it to separate from.
             The header's own `border-b` underneath is the only rule the strip
             needs, and it is the one doing real work. --}}
        {{ $attributes }}
        aria-label="Sections"
    >
        <div
            x-ref="strip"
            class="flex gap-1 overflow-x-auto px-4 py-1 lg:gap-5 lg:py-0 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
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
                        {{-- The lg restyle: square, transparent, drawn on the
                             header's own rule. The underline carries the
                             active state, so the chip fill retires with the
                             rounding. --}}
                        'lg:rounded-none lg:border-b-2 lg:bg-transparent lg:px-1 lg:pt-1.5 lg:pb-2 lg:hover:bg-transparent lg:dark:bg-transparent lg:dark:hover:bg-transparent',
                        'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100' => $current,
                        'lg:border-zinc-900 lg:dark:border-zinc-100' => $current,
                        'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-zinc-100' => ! $current,
                        'lg:border-transparent' => ! $current,
                    ])
                >{{ $section['label'] }}@if ($section['route'] === 'notifications' && (auth()->user()?->unreadNoteCount() ?? 0) > 0)<span class="ms-1.5 inline-block size-2 rounded-full bg-red-500 align-middle" aria-hidden="true"></span><span class="sr-only">unread</span>@endif</a>
            @endforeach
        </div>
    </nav>
@endif
