@php
    use App\Support\Brand;

    /*
     * Brand reads settings through cache and database. On a 404 both are
     * healthy — but the 500 page renders exactly when something is broken,
     * and an error page that can itself error collapses to the framework's
     * bare screen, which strands an installed app with no browser chrome to
     * escape through. The shipped constants are the floor.
     */
    $wordmark = rescue(fn () => Brand::wordmark(), Brand::WORDMARK, report: false);
    $brandName = rescue(fn () => Brand::name(), config('app.name'), report: false);
    $ink = rescue(fn () => Brand::color('ink'), Brand::COLORS['ink'], report: false);
@endphp
{{--
    The dead-end floor, shared by every status page. Self-contained like the
    offline page and for the same reason: no @vite, no @fonts, no shared
    layout, because an error page must render when nothing else does.

    The copy is static PG, not Voice::line(). Voice reads the session's user,
    and these pages render when the session may be the broken part — and a PG
    reader must never see a PG-13 line, so the register that can never climb
    the ladder is the only safe one to bake in.

    Every page ends in a way out. Installed to a home screen there is no
    address bar, no reload control and no back button: whatever link or
    button a status page yields here is the ONLY exit a stranded reader has.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
{{-- Matches partials/head: standalone has no chrome to un-zoom with. --}}
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="{{ $ink }}">
{{-- Pre-paint, try/catch: honor the appearance chosen INSIDE the app
     (flux.appearance in localStorage) — these self-contained pages themed
     by the OS alone, so a Light-mode reader hit a dark error page. No
     stored choice leaves the media query in charge. --}}
<script>
    try { var t = localStorage.getItem('flux.appearance'); if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t; } catch (e) {}
</script>

<title>@yield('title') &mdash; {{ $brandName }}</title>
<style>
    :root { color-scheme: light dark; }
    * { margin: 0; box-sizing: border-box; }
    body {
        min-height: 100dvh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem calc(1.5rem + env(safe-area-inset-right)) calc(1.5rem + env(safe-area-inset-bottom)) calc(1.5rem + env(safe-area-inset-left));
        font-family: ui-sans-serif, system-ui, sans-serif;
        background: #ffffff;
        color: #18181b;
        text-align: center;
        -webkit-font-smoothing: antialiased;
    }
    main { max-width: 24rem; }
    .wordmark { font-size: 0.8125rem; letter-spacing: 0.35em; text-transform: uppercase; color: #71717a; }
    .wordmark strong { display: block; font-size: 1.5rem; letter-spacing: -0.01em; text-transform: none; color: inherit; }
    h1 { margin-top: 2rem; font-size: 1.375rem; letter-spacing: -0.01em; }
    p { margin-top: 0.75rem; font-size: 0.9375rem; line-height: 1.6; color: #52525b; }
    .action {
        display: inline-block;
        margin-top: 1.5rem;
        padding: 0.625rem 1.25rem;
        border: 0;
        border-radius: 0.5rem;
        font: inherit;
        font-weight: 600;
        background: #2563eb;
        color: #ffffff;
        text-decoration: none;
        cursor: pointer;
    }
    .quiet {
        display: block;
        margin-top: 1rem;
        font-size: 0.875rem;
        color: #52525b;
        text-decoration: underline;
        text-underline-offset: 2px;
    }
    /* System dark, unless the reader chose Light in the app. */
    @media (prefers-color-scheme: dark) {
        :root:not([data-theme="light"]) body { background: #09090b; color: #f4f4f5; }
        :root:not([data-theme="light"]) .wordmark { color: #a1a1aa; }
        :root:not([data-theme="light"]) p { color: #a1a1aa; }
        :root:not([data-theme="light"]) .action { background: #3b82f6; }
        :root:not([data-theme="light"]) .quiet { color: #a1a1aa; }
    }
    /* The explicit in-app choice wins over any OS setting. */
    :root[data-theme="dark"] body { background: #09090b; color: #f4f4f5; }
    :root[data-theme="dark"] .wordmark { color: #a1a1aa; }
    :root[data-theme="dark"] p { color: #a1a1aa; }
    :root[data-theme="dark"] .action { background: #3b82f6; }
    :root[data-theme="dark"] .quiet { color: #a1a1aa; }
</style>
</head>
<body>
<main>
    <div class="wordmark">
        {{ $wordmark['lead'] }}
        <strong>{{ $wordmark['main'] }}</strong>
    </div>

    <h1>@yield('headline')</h1>
    <p>@yield('message')</p>

    @yield('actions')
</main>
</body>
</html>
