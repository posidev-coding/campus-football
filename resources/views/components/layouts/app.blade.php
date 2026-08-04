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
        Mobile-first, widening in additive steps. Nothing below `lg` is
        sacrificed to make the desktop layout work:

          base   single column, bottom nav, header nav hidden
          sm     header nav appears, bottom nav retires, cards go two-up
          lg     the right rail appears alongside — never instead of — content
          max    capped at 1280px, about a 14" laptop, so line lengths stay
                 readable rather than stretching across an external monitor
    --}}
    <div class="mx-auto flex min-h-dvh w-full max-w-7xl flex-col">
        <header class="sticky top-0 z-20 border-b border-zinc-200 bg-white/85 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/85">
            <div class="flex h-14 items-center gap-4 px-4">
                <a href="{{ route('home') }}" wire:navigate class="flex shrink-0 items-center gap-2 font-semibold tracking-tight">
                    <flux:icon name="trophy" variant="micro" class="text-zinc-400" />
                    <span class="hidden sm:inline">{{ config('app.name') }}</span>
                    <span class="sm:hidden">CFB</span>
                </a>

                <div class="ml-auto flex shrink-0 items-center gap-1">
                    <livewire:search />

                    @auth
                        <flux:dropdown position="bottom" align="end">
                            <flux:profile :initials="auth()->user()->initials()" :chevron="false" />

                            <flux:menu>
                                <flux:menu.item icon="user" href="#">{{ auth()->user()->name }}</flux:menu.item>
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
                        <flux:button :href="route('register')" wire:navigate size="sm" variant="primary" class="hidden sm:inline-flex">Sign up</flux:button>
                    @endauth
                </div>
            </div>

            {{-- The secondary nav, ESPN-style. Nine sections will not fit in a
                 header row, so they scroll horizontally on a phone and settle
                 into a full strip once there is room. --}}
            <x-section-nav class="border-b-0" />
        </header>

        <div class="flex flex-1 gap-6 px-4 py-5 pb-[calc(var(--nav-height)+1.5rem)] sm:pb-6">
            <main class="min-w-0 flex-1">
                {{ $slot }}
            </main>

            @if ($rail)
                {{-- The right rail is desktop-only and purely additive: it
                     appears at lg and above, and its absence below changes
                     nothing about the primary content. --}}
                <aside class="hidden w-72 shrink-0 flex-col gap-4 lg:flex">
                    <x-rankings-panel class="sticky top-[4.5rem]" />
                </aside>
            @endif
        </div>

        {{-- Thumb-reachable primary nav. Retires once the header can carry
             navigation on its own.

             Rendered for guests too: every destination here is public, and
             gating it behind auth left a signed-out visitor on a phone with no
             navigation whatsoever. --}}
        <nav
            class="fixed inset-x-0 bottom-0 z-20 border-t border-zinc-200 bg-white/95 backdrop-blur sm:hidden dark:border-zinc-800 dark:bg-zinc-950/95"
            style="padding-bottom: env(safe-area-inset-bottom);"
        >
            <div class="grid h-[var(--nav-height)] grid-cols-4">
                <x-nav-tab :href="route('home')" icon="home" label="Home" />
                <x-nav-tab :href="route('scoreboard')" icon="calendar-days" label="Scores" />
                <x-nav-tab :href="route('standings')" icon="table-cells" label="Standings" />
                <x-nav-tab :href="route('rankings')" icon="trophy" label="Rankings" />
            </div>
        </nav>
    </div>

    @fluxScripts
</body>
</html>
