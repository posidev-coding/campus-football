<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="motion-safe:scroll-smooth">
<head>
    {{-- Shared with layouts/auth so the two cannot drift. They held byte-identical
         heads before, which is exactly how one layout ends up without a favicon. --}}
    @include('partials.head')
</head>
<body class="min-h-dvh">
    {{-- Whose account this is, when it is not your own. First thing in the
         body so it is painted before anything else and cannot be missed;
         the session flag is set only by the panel's impersonate action. --}}
    @if (session()->has('impersonator_id'))
        @include('partials.impersonation-banner')
    @endif

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
             and in a browser tab the inset is 0 and nothing changes.

             The header PUBLISHES its measured height as `--chrome-offset`,
             which is what sticky screen chrome sticks against. It has to be
             measured rather than summed: `--header-offset` is the app bar
             alone, and in an area carrying a section strip that is short by
             the strip — sticky chrome then slid under it and vanished on
             every Picks and League screen. The strip is not a constant to
             add back (it wraps, and it restyles at `lg`), so the element
             that knows its own height is the one that says it. The variable
             goes on `document.documentElement`, never on this node: a
             Livewire morph strips inline styles it did not render. --}}
        <header
            x-data="{
                publishOffset() {
                    document.documentElement.style.setProperty(
                        '--chrome-offset', $el.offsetHeight + 'px'
                    )
                },
            }"
            x-init="
                publishOffset()
                new ResizeObserver(() => publishOffset()).observe($el)
            "
            x-on:resize.window="publishOffset()"
            @class([
                'sticky top-0 z-40 border-zinc-200 bg-white/85 pt-[env(safe-area-inset-top)] backdrop-blur sm:border-b dark:border-zinc-800 dark:bg-zinc-950/85',
                'border-b' => $hasSections,
            ])
        >
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
                    {{-- The gamification chips' `sm`-and-up home — the same
                         chips Home's brand bar carries below `sm`, where this
                         header does not exist. Same `data-tour` key on both:
                         the tour spotlights whichever is visible. --}}
                    @auth
                        <x-wallet-chips data-tour="wallet" />
                    @endauth

                    {{-- The wrapper exists to carry the tour's spotlight
                         target — attributes on a livewire: tag do not reach
                         its root, and `display: contents` has no box to
                         measure. --}}
                    <div class="flex" data-tour="search">
                        <livewire:search />
                    </div>

                    @auth
                        {{-- `relative`: the unread dot below positions
                             against the dropdown's own box. --}}
                        <flux:dropdown position="bottom" align="end" class="relative">
                            {{-- `avatar` is null for most people and always will
                                 be; initials are the normal state, not the
                                 fallback state. --}}
                            <flux:profile
                                :avatar="auth()->user()->avatarUrl()"
                                :initials="auth()->user()->initials()"
                                :chevron="false"
                                data-tour="account"
                            />

                            {{-- The unread dot, over the avatar's corner: the
                                 bottom bar retires at `sm`, and additive means
                                 the desktop header carries its own signal. A
                                 sibling, not a wrapper — ui-dropdown finds its
                                 trigger button, and this never eats a tap. --}}
                            @if (auth()->user()->unreadNoteCount() > 0)
                                <span class="pointer-events-none absolute top-0.5 right-0.5 size-2 rounded-full bg-red-500 ring-2 ring-white dark:ring-zinc-950" aria-hidden="true"></span>
                                <span class="sr-only">Unread notifications</span>
                            @endif

                            <flux:menu>
                                <flux:menu.item icon="user" :href="route('account')">{{ auth()->user()->name }}</flux:menu.item>
                                <flux:menu.separator />
                                {{-- Appearance, right where a desktop reader
                                     looks for it — the same shared control the
                                     Account screen renders, which keeps its
                                     own copy because below `sm` this menu does
                                     not exist. The heading is the name the
                                     icon-only control loses when the text
                                     radios go; the partial explains why it
                                     must be a radio group and not buttons
                                     (menu items close the popover per click,
                                     ui-radio is skipped by the walker). --}}
                                <flux:menu.heading>Appearance</flux:menu.heading>
                                <div class="px-1 pb-1">
                                    <x-appearance-switcher class="w-full" />
                                </div>
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
                    <div class="sticky top-[calc(var(--chrome-offset)+1rem)] flex max-h-[calc(100dvh-var(--chrome-offset)-2rem)] flex-col gap-4 overflow-y-auto overscroll-contain">
                        @foreach ($railPanels as $panel)
                            <x-dynamic-component :component="$panel" wire:key="rail-{{ $panel }}" />
                        @endforeach
                    </div>
                </aside>
            @endif
        </div>

        <x-bottom-nav />
    </div>

    {{-- App layout only, on purpose: a stray pull on an auth screen would
         reload a half-typed form into a blank one. --}}
    <x-pull-to-refresh />

    {{-- App layout suffices: every authenticated standalone session's FIRST
         load is an app-layout screen (start_url is /, and the register and
         login hand-offs are full redirects to Home), so an auth-layout
         standalone load can only follow a page that already reported. --}}
    <x-standalone-beacon />

    {{-- Last in body on purpose: an opaque background does not win a z-index
         tie, later DOM does — this is what puts the curtain over the tour
         scrim and the pull-to-refresh puck at the same z-50. --}}
    <x-boot-splash />

    @fluxScripts

    {{-- The depth counter behind every Back control lives in partials/head,
         beside the standalone stamp — shared, so a cold load on an auth page
         counts exactly like a cold load here. --}}
</body>
</html>
