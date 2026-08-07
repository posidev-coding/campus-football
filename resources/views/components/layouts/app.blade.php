@props(['rail' => true])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    {{-- Shared with layouts/auth so the two cannot drift. They held byte-identical
         heads before, which is exactly how one layout ends up without a favicon. --}}
    @include('partials.head')
</head>
<body class="min-h-dvh">
    {{-- Tints the mobile browser chrome to match. It lives in <body> because
         Alpine only initialises inside it — an `x-data` on the meta tag itself
         is never picked up. `x-effect` re-runs whenever `$flux.dark` changes,
         which covers all three cases: an explicit pick, and the OS flipping
         under "System". --}}
    <div
        x-data
        x-effect="document.querySelector('meta[name=theme-color]')
            ?.setAttribute('content', $flux.dark ? '#09090b' : '#ffffff')"
        hidden
    ></div>

    {{--
        Mobile-first, widening in additive steps. Nothing below `sm` is
        sacrificed to make the desktop layout work:

          base   no top bar at all. Navigation is the bottom tab bar (areas)
                 plus the area's own scrolling strip (sections). Content starts
                 at the top of the viewport.
          sm     the header ADDS brand, ⌘K search and the account menu, and the
                 bottom bar retires. Cards go two-up.
          lg     the right rail appears alongside — never instead of — content
          max    capped at 1280px, about a 14" laptop, so line lengths stay
                 readable rather than stretching across an external monitor

        The header is genuinely additive: everything in it is reachable at base
        through a tab. Brand → Home, search icon → Search, avatar → Account.
        That is the rule this layout previously broke in the other direction,
        when the bottom nav was auth-gated and a signed-out phone visitor had no
        navigation at all.
    --}}
    <div class="mx-auto flex min-h-dvh w-full max-w-7xl flex-col">
        {{-- z-40 puts app chrome above anything a screen sticks to its own
             viewport. A screen may stack internally (the scoreboard runs
             chrome 30 / day heading 20 / card contents 10); none of it may
             climb over the header or the tab bar. --}}
        @php $hasSections = count(App\Support\Navigation::currentSections()) > 1; @endphp

        {{-- The bottom border is conditional because below `sm` this header can
             be genuinely EMPTY — Scores is its own area with no section strip,
             so the bar is `sm:flex` and the strip renders nothing. An
             unconditional `border-b` left a 1px rule floating at the top of the
             screen with nothing above or below it, and gave anything sticking
             underneath 1px of travel before it settled. --}}
        <header @class([
            'sticky top-0 z-40 border-zinc-200 bg-white/85 backdrop-blur sm:border-b dark:border-zinc-800 dark:bg-zinc-950/85',
            'border-b' => $hasSections,
        ])>
            {{-- Reclaimed on mobile: 56px of brand mark, search icon and avatar
                 that the tab bar carries instead. --}}
            <div class="hidden h-14 items-center gap-4 px-4 sm:flex">
                {{-- This was a `trophy` glyph beside the app name — the same
                     glyph the League tab and the conference rows use, so the
                     brand mark and a navigation icon were one picture. --}}
                <a href="{{ route('home') }}" wire:navigate class="shrink-0">
                    <x-brand.lockup size="sm" />
                </a>

                <x-area-nav class="ml-4 hidden min-w-0 flex-1 md:flex" />

                <div class="ml-auto flex shrink-0 items-center gap-1">
                    <livewire:search />

                    @auth
                        <flux:dropdown position="bottom" align="end">
                            <flux:profile :initials="auth()->user()->initials()" :chevron="false" />

                            <flux:menu>
                                <flux:menu.item icon="user" :href="route('account')">{{ auth()->user()->name }}</flux:menu.item>
                                <flux:menu.separator />
                                @if (auth()->user()->isAdmin())
                                    <flux:menu.item icon="wrench-screwdriver" href="/admin">Admin</flux:menu.item>
                                @endif
                                <flux:menu.item
                                    icon="arrow-right-start-on-rectangle"
                                    onclick="document.getElementById('logout-form').submit()"
                                >
                                    Log out
                                </flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>

                        <form id="logout-form" method="POST" action="{{ route('logout') }}" class="hidden">
                            @csrf
                        </form>
                    @else
                        <flux:button :href="route('login')" wire:navigate size="sm" variant="ghost">Log in</flux:button>
                        <flux:button :href="route('register')" wire:navigate size="sm" variant="primary">Sign up</flux:button>
                    @endauth
                </div>
            </div>

            {{-- Sections for the CURRENT area only. Renders nothing when the
                 area has a single screen, or outside every area. --}}
            <x-section-nav />
        </header>

        <div class="flex flex-1 gap-6 px-4 py-5 pb-[calc(var(--nav-height)+1.5rem)] sm:pb-6">
            <main class="min-w-0 flex-1">
                {{ $slot }}
            </main>

            @if ($rail)
                {{-- Desktop-only and purely additive: it appears at lg and
                     above, and its absence below changes nothing about the
                     primary content. --}}
                <aside class="hidden w-72 shrink-0 flex-col gap-4 lg:flex">
                    <x-rankings-panel class="sticky top-[4.5rem]" />
                </aside>
            @endif
        </div>

        <x-bottom-nav />
    </div>

    @fluxScripts

    {{--
        How deep into the app this tab is — the only honest answer to "is
        there one of our own pages behind me in history".

        Neither signal you would reach for first works. `history.length`
        counts the blank new-tab page, so a shared link opened in a new tab
        reads as "go back" and walks the reader out of the site. And
        `document.referrer` does not change across a wire:navigate hop, so an
        in-app move looks identical to a cold load.

        `data-navigate-once` is what makes this work: the script runs once per
        DOCUMENT and is not re-executed by navigate, so the counter survives
        SPA hops and resets on a real load or reload. livewire:navigated fires
        on the initial render too, so 1 means "cold load, nothing behind us"
        and anything above it means back() lands on one of our pages.
    --}}
    <script data-navigate-once>
        window.cfbAppDepth = 0;
        document.addEventListener('livewire:navigated', () => window.cfbAppDepth++);
    </script>
</body>
</html>
