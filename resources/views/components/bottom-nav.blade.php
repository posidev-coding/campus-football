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

    The column count comes from the area list rather than being hardcoded, so
    adding Pick'em as a sixth area is a one-line change here.
--}}
<nav
    {{-- z-40, matching the header: app chrome always sits above whatever a
         screen sticks to its own viewport. A sticky day heading low on the
         page would otherwise paint over the tab bar. --}}
    class="fixed inset-x-0 bottom-0 z-40 border-t border-zinc-200 bg-white/95 backdrop-blur sm:hidden dark:border-zinc-800 dark:bg-zinc-950/95"
    style="padding-bottom: env(safe-area-inset-bottom);"
    aria-label="Primary"
>
    <div class="grid h-[var(--nav-height)]" style="grid-template-columns: repeat({{ count($areas) }}, minmax(0, 1fr));">
        @foreach ($areas as $area)
            <x-nav-tab
                :href="Navigation::href($area)"
                :icon="$area['icon']"
                :label="Navigation::label($area)"
                :active="Navigation::isCurrent($area)"
                wire:key="area-{{ $area['key'] }}"
            />
        @endforeach
    </div>
</nav>
