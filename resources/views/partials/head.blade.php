@php use App\Support\Brand; @endphp

<meta charset="utf-8">
{{-- `maximum-scale=1, user-scalable=no` is the zoom lock. Installed, there is
     no browser chrome to un-zoom with: iOS auto-zooms any focused input whose
     text is under 16px, and after adding a team from Home's swiper the app was
     left slightly enlarged and scrolling sideways, permanently. In a browser
     TAB iOS ignores `user-scalable=no` (accessibility pinch survives there);
     what this retires is the focus auto-zoom everywhere and pinch inside the
     installed app — exactly the split we want. --}}
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">

{{-- Kept in step with the chosen appearance by the sync element at the top of
     <body>. Hardcoded dark, a phone's address bar stayed black after switching
     to Light — which the appearance control made visible.

     There must be exactly ONE of these on the page. The brand's own head
     snippet ships a media-scoped PAIR (dark + light), and adding it would make
     the sync's `querySelector('meta[name=theme-color]')` return the dark one
     and silently undo the fix. BrandingTest counts them. --}}
<meta name="theme-color" content="#09090b">

{{-- iOS launches meta-driven web clips (including ones added from Chrome's
     or Firefox's share sheet) with `navigator.standalone` true while the
     `display-mode: standalone` media query still reports `browser` — so the
     CSS that hides install-selling chrome never fired and the installed app
     pitched its own install. Stamping the root BEFORE first paint keeps the
     hide stylesheet-driven and flash-free on that signal too. --}}
<script>if (navigator.standalone) document.documentElement.setAttribute('data-standalone', '')</script>

{{-- The boot splash's cold-start stamp, and it must sit ABOVE the depth
     counter: `cfbAppDepth === undefined` is what makes this a real-document-
     load detector — on a cold load the counter does not exist yet, and on
     any navigate-hop re-evaluation it already does, so a hop can never
     stamp. Pre-paint, because the curtain has to be up before Alpine exists
     (the install-banner lesson: first-paint chrome is never gated on JS).
     The splash's own end() removes the attribute; the stylesheet carries an
     8s dead-man for a boot where JS never ran. Loads that stamp = cold
     open, re-open, a notification deep-link, the post-onboarding redirect
     (`navigate`/`back_forward`) — the set that should feel like a launch.
     A RELOAD does not: in standalone there is no reload chrome, so
     `type === 'reload'` is a near-exact proxy for "the user pulled", and
     pull-to-refresh's spinner puck is that gesture's whole experience.
     The `?.` fails OPEN — an engine without the entry behaves as a launch
     and shows the splash. --}}
<script>
    if (window.cfbAppDepth === undefined
        && (window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true)
        && performance.getEntriesByType('navigation')[0]?.type !== 'reload') {
        document.documentElement.setAttribute('data-boot', '');
    }
</script>

{{--
    How deep into the app this tab is — the only honest answer to "is there
    one of our own pages behind me in history", and what every Back control
    (the game scorebug, the auth screens) decides with.

    Neither signal you would reach for first works. `history.length` counts
    the blank new-tab page, so a shared link opened in a new tab reads as "go
    back" and walks the reader out of the site. And `document.referrer` does
    not change across a wire:navigate hop, so an in-app move looks identical
    to a cold load.

    It lives in the shared head, not a layout: the auth layout's Back needs
    the count too, and a counter defined by only ONE layout resets to
    undefined whenever a cold load lands on the other. The undefined-guard
    makes re-evaluation a no-op, so however Livewire treats this element on a
    navigate hop, the count survives and the listener stays single.

    livewire:navigated fires on the initial render too, so 1 means "cold
    load, nothing behind us" and anything above it means back() lands on one
    of our pages.
--}}
<script>
    if (window.cfbAppDepth === undefined) {
        window.cfbAppDepth = 0;
        document.addEventListener('livewire:navigated', () => window.cfbAppDepth++);
    }
</script>

<title>{{ $title ?? Brand::name() }}</title>

{{-- Icons. Every URL comes from Brand, so an upload on the App Branding page
     reaches the tab, the home screen and the share card together — three
     hardcoded paths is how they end up being three different brands.

     The 192px PNG rides `rel="icon"` as well as the manifest, for the icon
     pipelines that never read `apple-touch-icon`: Firefox on iOS builds its
     home-screen web clip from its OWN favicon store, and with only the 32px
     ico and an SVG to choose from it fell back to the gray letter monogram.
     A real bitmap under rel="icon" is the biggest thing that store can hold. --}}
<link rel="icon" href="{{ route('favicon') }}" sizes="32x32">
<link rel="icon" href="{{ Brand::asset('favicon-svg') }}" type="image/svg+xml">
<link rel="icon" href="{{ Brand::asset('icon-192') }}" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" sizes="180x180" href="{{ Brand::asset('apple-touch') }}">
<link rel="manifest" href="{{ route('manifest') }}">

{{-- Add to Home Screen on iOS. `apple-mobile-web-app-title` is the label under
     the icon; the capability metas make the launched app standalone, matching
     the manifest's own `display` — both spellings, because iOS before 17.4
     reads only the `apple-` prefixed one. `black-translucent` draws under the
     status bar, which is safe because the viewport is `viewport-fit=cover`,
     the body pads the side insets, the layout header pads
     `env(safe-area-inset-top)` and screen chrome clears the whole header —
     that inset included — via the measured `--chrome-offset`. --}}
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
