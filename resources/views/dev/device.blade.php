@php
    /**
     * Local-only responsive preview harness.
     *
     * Chrome enforces a minimum window width of roughly 600px, so resizing the
     * browser cannot reach a real phone viewport — a request for 390px is
     * silently clamped and every media query below `sm` evaluates wrong. An
     * iframe has no such floor, so this renders the app at exact device widths
     * side by side and the breakpoints behave honestly.
     *
     * Usage: /__device?path=/scoreboard&w=390,430,768
     */
    $path = request()->string('path')->toString() ?: '/';

    // Same-origin relative paths only. This is a dev tool, but pointing an
    // iframe at an arbitrary host because a query string said so is a bad habit
    // regardless of environment.
    if (! str_starts_with($path, '/')) {
        $path = '/'.ltrim($path, '/');
    }

    $widths = collect(explode(',', request()->string('w')->toString() ?: '390,768'))
        ->map(fn ($w) => (int) trim($w))
        ->filter(fn ($w) => $w >= 280 && $w <= 1600)
        ->values();

    $height = (int) (request()->integer('h') ?: 844);
    $dark = request()->boolean('dark');
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Device preview — {{ $path }}</title>
    <style>
        :root { color-scheme: dark; }
        body {
            margin: 0;
            padding: 16px;
            background: #27272a;
            font: 12px/1.4 ui-sans-serif, system-ui, sans-serif;
            color: #a1a1aa;
        }
        .rail { display: flex; gap: 16px; align-items: flex-start; overflow-x: auto; }
        .device { display: flex; flex-direction: column; gap: 6px; flex: none; }
        .label { display: flex; justify-content: space-between; padding: 0 2px; }
        .label b { color: #e4e4e7; font-weight: 600; }
        iframe { border: 0; border-radius: 14px; background: #fff; box-shadow: 0 0 0 1px #52525b; }
        form { margin-bottom: 14px; display: flex; gap: 8px; flex-wrap: wrap; }
        input, button {
            background: #3f3f46; color: #e4e4e7; border: 1px solid #52525b;
            border-radius: 6px; padding: 5px 9px; font: inherit;
        }
        button { cursor: pointer; }
    </style>
</head>
<body>
    <form method="GET">
        <input name="path" value="{{ $path }}" size="42" placeholder="/scoreboard">
        <input name="w" value="{{ $widths->implode(',') }}" size="14" placeholder="390,768">
        <input name="h" value="{{ $height }}" size="6">
        <label style="display:flex;align-items:center;gap:5px">
            <input type="checkbox" name="dark" value="1" @checked($dark) style="width:auto"> dark
        </label>
        <button type="submit">Preview</button>
    </form>

    <div class="rail">
        @foreach ($widths as $width)
            <div class="device">
                <div class="label">
                    <b>{{ $width }}px</b>
                    <span>{{ $width < 640 ? 'below sm' : ($width < 768 ? 'sm' : ($width < 1024 ? 'md' : 'lg+')) }}</span>
                </div>
                <iframe
                    src="{{ $path }}"
                    width="{{ $width }}"
                    height="{{ $height }}"
                    data-width="{{ $width }}"
                    loading="eager"
                ></iframe>
            </div>
        @endforeach
    </div>

    @if ($dark)
        <script>
            // Flux toggles dark mode with a class on <html>, so reach into each
            // frame once it has loaded rather than relying on the OS setting.
            document.querySelectorAll('iframe').forEach((frame) => {
                frame.addEventListener('load', () => {
                    frame.contentDocument?.documentElement.classList.add('dark');
                });
            });
        </script>
    @endif
</body>
</html>
