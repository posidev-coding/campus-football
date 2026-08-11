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

    <div class="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
        <div class="w-full max-w-sm">
            <a href="{{ route('home') }}" wire:navigate class="mb-8 flex justify-center">
                <x-brand.lockup stacked size="lg" />
            </a>

            {{ $slot }}
        </div>
    </div>

    @fluxScripts
</body>
</html>
