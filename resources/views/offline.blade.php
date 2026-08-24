@php use App\Support\Brand; @endphp
{{--
    The service worker's floor: precached at install, served when a navigation
    has no network. Self-contained on purpose — no @vite, no @fonts, no shared
    layout — because every external reference is one more thing that has to be
    in the cache for this page to render at all.

    The copy is static PG, not Voice::line(): the worker caches this page ONCE
    per device at install time, so whatever register rendered would be frozen
    for every future reader of that cache — and a PG user must never see a
    PG-13 line. PG is the only register that can never climb the ladder.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
{{-- Matches partials/head: the zoom lock matters MOST here, since this page
     only ever renders inside the installed app or a flaky browser tab. --}}
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="{{ Brand::color('ink') }}">
{{-- Pre-paint, try/catch: honor the appearance chosen INSIDE the app
     (flux.appearance in localStorage) — these self-contained pages themed
     by the OS alone, so a Light-mode reader hit a dark error page. No
     stored choice leaves the media query in charge. --}}
<script>
    try { var t = localStorage.getItem('flux.appearance'); if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t; } catch (e) {}
</script>

<title>Offline — {{ Brand::name() }}</title>
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
    button {
        margin-top: 1.5rem;
        padding: 0.625rem 1.25rem;
        border: 0;
        border-radius: 0.5rem;
        font: inherit;
        font-weight: 600;
        background: #2563eb;
        color: #ffffff;
        cursor: pointer;
    }
    /* System dark, unless the reader chose Light in the app. */
    @media (prefers-color-scheme: dark) {
        :root:not([data-theme="light"]) body { background: #09090b; color: #f4f4f5; }
        :root:not([data-theme="light"]) .wordmark { color: #a1a1aa; }
        :root:not([data-theme="light"]) p { color: #a1a1aa; }
        :root:not([data-theme="light"]) button { background: #3b82f6; }
    }
    /* The explicit in-app choice wins over any OS setting. */
    :root[data-theme="dark"] body { background: #09090b; color: #f4f4f5; }
    :root[data-theme="dark"] .wordmark { color: #a1a1aa; }
    :root[data-theme="dark"] p { color: #a1a1aa; }
    :root[data-theme="dark"] button { background: #3b82f6; }
</style>
</head>
<body>
<main>
    <div class="wordmark">
        {{ Brand::wordmark()['lead'] }}
        <strong>{{ Brand::wordmark()['main'] }}</strong>
    </div>

    <h1>You&rsquo;re offline.</h1>
    <p>No connection, no scores &mdash; the one matchup we can&rsquo;t call for you. Everything picks back up the moment you&rsquo;re reconnected.</p>

    <button type="button" onclick="location.reload()">Try again</button>
</main>
</body>
</html>
