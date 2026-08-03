<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
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
    <div class="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
        <div class="w-full max-w-sm">
            <a
                href="{{ route('home') }}"
                wire:navigate
                class="mb-8 flex items-center justify-center gap-2 text-lg font-semibold tracking-tight"
            >
                <flux:icon name="trophy" variant="mini" class="text-zinc-400" />
                {{ config('app.name') }}
            </a>

            {{ $slot }}
        </div>
    </div>

    @fluxScripts
</body>
</html>
