@php
    use App\Support\Navigation;

    $areas = Navigation::areas();
@endphp

{{--
    The primary navigation on a phone: AREAS, not sections.

    Areas are stable — they do not change as you move around inside one — which
    is what makes the bar a reliable place to reach for. The scrolling strip at
    the top handles the churn within an area.

    Rendered for guests too. Every destination is public except Account, which
    resolves to sign-in rather than disappearing: this bar is the only
    navigation at this width, so a tab that vanishes for a signed-out visitor
    takes the sign-in route with it.

    The column count comes from the area list rather than being hardcoded —
    which is how Picks arrived as the fifth tab without this file changing.
--}}
{{--
    Anchored to the VISUAL viewport, not the layout one.

    `fixed` resolves against the layout viewport, and iOS standalone is
    perfectly willing to hand back a stale one: leave the app and come back and
    the bar renders against a bottom edge that is no longer on screen, which
    reads as the tab bar having floated halfway up the page and now scrolling
    with it. A focused input does the same thing for as long as the keyboard is
    up — installed, iOS scrolls the visual viewport inside an unchanged layout
    viewport rather than resizing anything, so the bar goes right on sitting at
    a bottom edge the reader cannot see.

    So the bar stops trusting the layout viewport and measures the one the
    reader actually has: `--viewport-bottom` is the distance from the visual
    viewport's bottom edge up to the layout viewport's, and the bar offsets by
    it. Nothing is a constant here — the number is whatever the two viewports
    currently disagree by, which is 0 the moment they agree again, so the
    correction unwinds itself and the bar never has to be told to go back.

    The variable goes on `document.documentElement`, never on this node: a
    Livewire morph strips inline styles it did not render, and this bar
    outlives every morph under it.

    Gated on BOTH standalone signals, exactly like pull-to-refresh — a browser
    tab publishes 0 and behaves as it always has. That matters here beyond
    tidiness: in a TAB the visual viewport genuinely shrinks and grows as the
    URL bar collapses, and a bar tracking that would jitter its way down every
    scroll. The two viewports only lie to each other once the browser chrome is
    gone.
--}}
<nav
    x-data="{
        publish() {
            let viewport = window.visualViewport;

            let standalone = window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true;

            // Never a default: with no visualViewport to read, or in a tab,
            // there is no disagreement to correct and the bar sits at 0.
            let gap = standalone && viewport
                ? Math.max(0, Math.round(
                    document.documentElement.clientHeight - viewport.height - viewport.offsetTop
                ))
                : 0;

            document.documentElement.style.setProperty('--viewport-bottom', gap + 'px');
        },
    }"
    x-init="
        window.cfbPublishViewportBottom = () => publish()
        publish()

        // Bound to the viewport once per document, not once per Alpine init:
        // `wire:navigate` re-runs this block on every hop, and visualViewport
        // listeners cannot be delegated to window. The handlers call through
        // the window function, so a torn-down scope can never be captured.
        if (! window.cfbViewportBottomBound) {
            window.cfbViewportBottomBound = true

            window.visualViewport?.addEventListener('resize', () => window.cfbPublishViewportBottom())
            window.visualViewport?.addEventListener('scroll', () => window.cfbPublishViewportBottom())
        }
    "
    {{-- The resume itself. `visibilitychange` catches coming back to the app,
         `pageshow` catches a restore out of the back/forward cache — the two
         ways the app returns without loading a document. Whichever of them
         reads a viewport iOS has not settled yet, the visualViewport `resize`
         above lands the correction when it does. --}}
    x-on:pageshow.window="publish()"
    x-on:visibilitychange.document="publish()"
    {{-- z-40, matching the header: app chrome always sits above whatever a
         screen sticks to its own viewport. A sticky day heading low on the
         page would otherwise paint over the tab bar. --}}
    class="fixed inset-x-0 bottom-[var(--viewport-bottom,0px)] z-40 border-t border-zinc-200 bg-white/95 backdrop-blur sm:hidden dark:border-zinc-800 dark:bg-zinc-950/95"
    style="padding-bottom: env(safe-area-inset-bottom);"
    aria-label="Primary"
>
    <div class="grid h-[var(--nav-height)]" style="grid-template-columns: repeat({{ count($areas) }}, minmax(0, 1fr));">
        @foreach ($areas as $area)
            {{-- `data-tour` marks the guided tour's spotlight targets; the
                 tour picks whichever element wearing a key is visible, so
                 these tabs serve below `sm` and the header chips above. --}}
            @php
                // Two dots, two meanings: unread notes on Account, and a
                // week still waiting on the reader behind Picks — the
                // latter answered from PickemPulse's five-minute cache,
                // never a fresh read per page.
                $dot = match ($area['key']) {
                    'account' => (auth()->user()?->unreadNoteCount() ?? 0) > 0,
                    'picks' => auth()->check() && App\Support\PickemPulse::needsAttention(auth()->user()),
                    default => false,
                };
            @endphp

            <x-nav-tab
                :href="Navigation::href($area)"
                :icon="$area['icon']"
                :label="Navigation::label($area)"
                :active="Navigation::isCurrent($area)"
                :badge="$dot"
                :badge-label="$area['key'] === 'picks' ? 'Picks waiting' : 'Unread notifications'"
                wire:key="area-{{ $area['key'] }}"
                data-tour="{{ $area['key'] }}"
            />
        @endforeach
    </div>
</nav>
