<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-dvh">
    {{-- Same theme-color sync as layouts/app — without it, light-mode sign-in
         kept the hardcoded dark meta and the browser chrome sat black over a
         white page. One element, reusing the head's single meta tag. --}}
    <div
        x-data
        x-effect="document.querySelector('meta[name=theme-color]')
            ?.setAttribute('content', $flux.dark ? '#09090b' : '#ffffff')"
        hidden
    ></div>

    {{-- The way OUT. Installed to a home screen there is no browser chrome,
         so a guest who tapped "Log in" and changed their mind was stuck: the
         lockup below does link home, but nothing about a logo says so. Same
         depth-aware behavior as the game scorebug's Back — our own history
         when there is one, Home when this screen IS the history (a cold
         launch straight onto /login). Offsets ride the safe-area insets so
         standalone's status bar and notch cannot swallow it. --}}
    <div class="fixed top-[calc(env(safe-area-inset-top)+0.5rem)] left-[calc(env(safe-area-inset-left)+0.5rem)] z-40">
        <button
            type="button"
            x-data="{
                back() {
                    window.cfbAppDepth > 1
                        ? window.history.back()
                        : Livewire.navigate(@js(route('home')));
                },
            }"
            x-on:click="back()"
            class="rounded-md px-2.5 py-1.5 text-sm font-medium text-zinc-600 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
        >Back</button>
    </div>

    <div class="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
        <div class="w-full max-w-sm">
            <a href="{{ route('home') }}" wire:navigate class="mb-8 flex justify-center">
                <x-brand.lockup stacked size="lg" />
            </a>

            {{ $slot }}
        </div>
    </div>

    {{-- Here too: a cold standalone open normally lands on `/`, but an
         expired session redirects that load HERE — a real document load on
         this layout, and a launch that skips its splash reads as a glitch.
         Browser tabs stay inert via the stylesheet gate. --}}
    <x-boot-splash />

    @fluxScripts
</body>
</html>
