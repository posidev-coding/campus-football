@props(['rail' => true])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#09090b">

    <title>{{ $title ?? config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-dvh">
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
        <header class="sticky top-0 z-20 border-b border-zinc-200 bg-white/85 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/85">
            {{-- Reclaimed on mobile: 56px of brand mark, search icon and avatar
                 that the tab bar carries instead. --}}
            <div class="hidden h-14 items-center gap-4 px-4 sm:flex">
                <a href="{{ route('home') }}" wire:navigate class="flex shrink-0 items-center gap-2 font-semibold tracking-tight">
                    <flux:icon name="trophy" variant="micro" class="text-zinc-400" />
                    <span>{{ config('app.name') }}</span>
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
</body>
</html>
