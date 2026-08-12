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
    {{-- 1280px through `lg`, 1440px from `xl` up. The step is at `xl` and not
         `2xl` on purpose: Tailwind's `2xl` is 1536px, so gating there would
         leave every laptop — a 14" MacBook is 1512pt — on the narrow shell
         and make the wider layout invisible on the machines it was built for.
         The extra 160px goes to the rail and to grid columns; line length is
         unchanged, and article prose stays capped at 68ch regardless. --}}
    <div class="mx-auto flex min-h-dvh w-full max-w-7xl flex-col xl:max-w-[90rem]">
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
             underneath 1px of travel before it settled.

             `pt-[env(safe-area-inset-top)]` is the standalone status-bar veil:
             installed to a home screen, the app draws under the Dynamic Island
             (`viewport-fit=cover` + `black-translucent`), and this padding is
             what keeps chrome and content out from behind it. The header
             renders on EVERY screen — even "empty" below `sm` — so in
             standalone its translucent blur is exactly a status-bar backdrop,
             and in a browser tab the inset is 0 and nothing changes. Screen
             chrome at z-30 offsets by the same inset via `--header-offset`. --}}
        <header @class([
            'sticky top-0 z-40 border-zinc-200 bg-white/85 pt-[env(safe-area-inset-top)] backdrop-blur sm:border-b dark:border-zinc-800 dark:bg-zinc-950/85',
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

                {{-- From `sm`, not `md`: the bottom tab bar retires at `sm`,
                     so a 640-767px window with `md:flex` here had NO primary
                     navigation at all — the exact failure the additive rule
                     exists to prevent. --}}
                <x-area-nav class="ml-4 hidden min-w-0 flex-1 sm:flex" />

                <div class="ml-auto flex shrink-0 items-center gap-1 lg:gap-2">
                    {{-- The wrapper exists to carry the tour's spotlight
                         target — attributes on a livewire: tag do not reach
                         its root, and `display: contents` has no box to
                         measure. --}}
                    <div class="flex" data-tour="search">
                        <livewire:search />
                    </div>

                    @auth
                        <flux:dropdown position="bottom" align="end">
                            {{-- `avatar` is null for most people and always will
                                 be; initials are the normal state, not the
                                 fallback state. --}}
                            <flux:profile
                                :avatar="auth()->user()->avatarUrl()"
                                :initials="auth()->user()->initials()"
                                :chevron="false"
                                data-tour="account"
                            />

                            <flux:menu>
                                <flux:menu.item icon="user" :href="route('account')">{{ auth()->user()->name }}</flux:menu.item>
                                <flux:menu.separator />
                                {{-- Appearance, right where a desktop reader
                                     looks for it. `$flux.appearance` is the
                                     same per-browser store the Account
                                     screen's segmented control writes — two
                                     controls, one localStorage truth, so
                                     they can never disagree. Account keeps
                                     its own because below `sm` this menu
                                     does not exist. --}}
                                <flux:menu.radio.group x-data x-model="$flux.appearance">
                                    <flux:menu.radio value="light">Light</flux:menu.radio>
                                    <flux:menu.radio value="dark">Dark</flux:menu.radio>
                                    <flux:menu.radio value="system">Match system</flux:menu.radio>
                                </flux:menu.radio.group>
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

        {{-- The bottom pad counts the tab bar's own safe-area padding too — the
             bar is `--nav-height` PLUS the home-indicator inset on a notched
             phone, and counting only the height trapped the last ~34px of
             every screen behind it in standalone.

             `px-4` is CONSTANT at every width, deliberately. A dozen blocks
             across eleven files cancel it with `-mx-4` to bleed to the edge of
             the content column — the scoreboard and game chrome, the day
             headings, the team hero, the week scroller, Home's two swiper
             tracks — and only three of them revert at a breakpoint. A
             responsive gutter would mean re-deriving all twelve, plus the
             `w-[calc(100%+2rem)]` on the article lead image, to buy nothing:
             at 1280 the content box is already 1248px and every card carries
             its own border and padding, so 16px of page gutter reads as
             deliberate edge-to-edge density. --}}
        <div class="flex flex-1 gap-6 px-4 py-5 pb-[calc(var(--nav-height)+1.5rem+env(safe-area-inset-bottom))] sm:pb-[calc(var(--spacing)*6+env(safe-area-inset-bottom))]">
            <main class="min-w-0 flex-1">
                {{ $slot }}
            </main>

            {{-- CONTEXTUAL, and desktop-only. App\Support\Rail is the single
                 source of truth for which screens carry one, the same
                 route-keyed shape App\Support\Navigation uses for the tab bar
                 and the section strip. An empty list emits no <aside> at all,
                 so the screen renders full width rather than beside a dead
                 column — which is exactly what every screen did while the
                 Top 25 panel was silently returning nothing.

                 Purely additive: the whole thing is `lg:flex`, and every
                 panel's content is reachable from a phone through a tab. --}}
            @php $railPanels = App\Support\Rail::panels(); @endphp

            @if ($railPanels !== [])
                <aside class="hidden w-72 shrink-0 flex-col gap-4 lg:flex">
                    {{-- The STACK sticks, not each panel: two sticky siblings
                         cannot both hold the top, and the second would scroll
                         away. Capped to the viewport and scrollable, so a
                         stack taller than the screen is still reachable —
                         `overflow-y` is fine, the app-wide ban is on
                         horizontal scroll. Nothing inside may be a dropdown
                         or a menu: this box clips them, and `sticky` opens a
                         stacking context a `fixed` child cannot escape. --}}
                    <div class="sticky top-[calc(var(--header-offset)+1rem)] flex max-h-[calc(100dvh-var(--header-offset)-2rem)] flex-col gap-4 overflow-y-auto overscroll-contain">
                        @foreach ($railPanels as $panel)
                            <x-dynamic-component :component="$panel" wire:key="rail-{{ $panel }}" />
                        @endforeach
                    </div>
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
