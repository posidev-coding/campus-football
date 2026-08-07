<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-dvh">
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
