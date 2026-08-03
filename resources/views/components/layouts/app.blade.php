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
    <div class="mx-auto flex min-h-dvh w-full max-w-2xl flex-col">
        <header class="sticky top-0 z-20 border-b border-zinc-200 bg-white/85 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/85">
            <div class="flex h-14 items-center justify-between gap-3 px-4">
                <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-2 font-semibold tracking-tight">
                    <flux:icon name="trophy" variant="micro" class="text-zinc-400" />
                    {{ config('app.name') }}
                </a>

                <nav class="hidden items-center gap-1 sm:flex">
                    <flux:button :href="route('scoreboard')" wire:navigate size="sm" variant="ghost">Scores</flux:button>
                    <flux:button :href="route('standings')" wire:navigate size="sm" variant="ghost">Standings</flux:button>
                </nav>

                <div class="flex items-center gap-1">
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
                        <flux:button :href="route('register')" wire:navigate size="sm" variant="primary">Sign up</flux:button>
                    @endauth
                </div>
            </div>
        </header>

        <main class="flex-1 px-4 py-5 pb-[calc(var(--nav-height)+1.5rem)] sm:pb-6">
            {{ $slot }}
        </main>

        {{-- Thumb-reachable primary nav. Hidden once there's room for the
             header to carry navigation on its own. --}}
        @auth
            <nav
                class="fixed inset-x-0 bottom-0 z-20 mx-auto max-w-2xl border-t border-zinc-200 bg-white/95 backdrop-blur sm:hidden dark:border-zinc-800 dark:bg-zinc-950/95"
                style="padding-bottom: env(safe-area-inset-bottom);"
            >
                <div class="grid h-[var(--nav-height)] grid-cols-4">
                    <x-nav-tab :href="route('home')" icon="home" label="Home" />
                    <x-nav-tab :href="route('scoreboard')" icon="calendar-days" label="Scores" />
                    <x-nav-tab :href="route('standings')" icon="table-cells" label="Standings" />
                    <x-nav-tab href="#" icon="clipboard-document-check" label="Picks" />
                </div>
            </nav>
        @endauth
    </div>

    @fluxScripts
</body>
</html>
