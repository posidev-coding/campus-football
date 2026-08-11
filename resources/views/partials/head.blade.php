@php use App\Support\Brand; @endphp

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">

{{-- Kept in step with the chosen appearance by the sync element at the top of
     <body>. Hardcoded dark, a phone's address bar stayed black after switching
     to Light — which the appearance control made visible.

     There must be exactly ONE of these on the page. The brand's own head
     snippet ships a media-scoped PAIR (dark + light), and adding it would make
     the sync's `querySelector('meta[name=theme-color]')` return the dark one
     and silently undo the fix. BrandingTest counts them. --}}
<meta name="theme-color" content="#09090b">

<title>{{ $title ?? Brand::name() }}</title>

{{-- Icons. Every URL comes from Brand, so an upload on the App Branding page
     reaches the tab, the home screen and the share card together — three
     hardcoded paths is how they end up being three different brands. --}}
<link rel="icon" href="{{ route('favicon') }}" sizes="32x32">
<link rel="icon" href="{{ Brand::asset('favicon-svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" sizes="180x180" href="{{ Brand::asset('apple-touch') }}">
<link rel="manifest" href="{{ route('manifest') }}">

{{-- Add to Home Screen on iOS. `apple-mobile-web-app-title` is the label under
     the icon; the capability metas make the launched app standalone, matching
     the manifest's own `display` — both spellings, because iOS before 17.4
     reads only the `apple-` prefixed one. `black-translucent` draws under the
     status bar, which is safe because the viewport is `viewport-fit=cover`,
     the body pads the side insets, the layout header pads
     `env(safe-area-inset-top)` and screen chrome offsets by the same inset
     via `--header-offset`. --}}
<meta name="apple-mobile-web-app-title" content="{{ Brand::shortName() }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

{{-- Launch screens. Android composes its own from the manifest; iOS instead
     wants a pre-rendered image per device size, matched by media query, and
     shows a white flash without one. Generated through Brand like the favicon
     and manifest, so a rebrand retints the launch screen with everything
     else. `?v=` for the same reason every uploaded asset carries one. --}}
@foreach (Brand::SPLASH as [$w, $h, $dpr])
    <link
        rel="apple-touch-startup-image"
        media="(device-width: {{ $w }}px) and (device-height: {{ $h }}px) and (-webkit-device-pixel-ratio: {{ $dpr }}) and (orientation: portrait)"
        href="{{ route('brand.splash', ['spec' => "{$w}x{$h}@{$dpr}"]) }}?v={{ Brand::settings()['version'] ?? 0 }}"
    >
@endforeach

<meta name="description" content="{{ Brand::tagline() }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ Brand::name() }}">
<meta property="og:title" content="{{ $title ?? Brand::name() }}">
<meta property="og:description" content="{{ Brand::tagline() }}">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:image" content="{{ Brand::asset('og-image') }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">

{{-- Emits the preload link and the @font-face block for the self-hosted
     variable font. @vite does NOT do this on its own — the font was declared in
     the theme and never actually loaded until this was added, which reads as a
     font that "doesn't look right" rather than one that is missing. --}}
@fonts
@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

{{-- Runtime brand colors, and ONLY where they differ from what Tailwind already
     compiled. A stock install renders no style block at all. --}}
@if ($brandCss = Brand::cssVariables())
    <style>{{ $brandCss }}</style>
@endif
