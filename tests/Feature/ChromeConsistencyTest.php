<?php

/*
 * The League chrome speaks one vocabulary, and these sweeps are what keep it
 * spoken: each control idiom lives in exactly one component, and reaching for
 * the old inline markup is a red test rather than a quiet drift.
 *
 * The load-bearing rule is the first one: NOTHING scrolls horizontally except
 * the week scroller (a season's weeks are a spatial sequence you scrub
 * along), the section nav (six sections measure 461px at 390 — they cannot
 * fit, and navigation auto-centers its active item), and Home's team swiper
 * (content, not a control — the swipe IS the interaction). Every other list
 * that outgrows its row belongs in a menu that scrolls vertically.
 */

use Symfony\Component\Finder\Finder;

/** @return array<string, string> path (relative to views) => contents */
function bladeViews(): array
{
    $views = [];

    // `filament/` is excluded: the admin panel renders inside Filament's own
    // design system, and the chrome vocabulary these sweeps enforce is the
    // PUBLIC app's. Holding an admin table to the phone-first no-horizontal-
    // scroll rule would be enforcing the right rule on the wrong product.
    foreach (Finder::create()->files()->in(resource_path('views'))->exclude('filament')->name('*.blade.php') as $file) {
        $views[str_replace(resource_path('views').'/', '', $file->getPathname())] = $file->getContents();
    }

    return $views;
}

it('finds views to sweep, or every check below is vacuous', function () {
    expect(bladeViews())->not->toBeEmpty();
});

it('scrolls horizontally only where the rule allows', function () {
    $allowed = [
        'components/week-scroller.blade.php',
        'components/section-nav.blade.php',
        'livewire/home.blade.php',
    ];

    $violations = [];

    foreach (bladeViews() as $path => $contents) {
        if (str_contains($contents, 'overflow-x-auto') && ! in_array($path, $allowed, true)) {
            $violations[] = $path;
        }
    }

    // Data tables scroll inside `stat-grid`, which is a CSS utility rather
    // than this class — the ban is on chrome and the document, not on a wide
    // box score in its own container.
    expect($violations)->toBe([], implode(', ', $violations)
        .' — scrolls horizontally. Overflowing option sets belong in an'
        .' x-filter-menu; fixed sets that fit at 390px in an x-pill-strip.');
});

it('keeps sr-only text inside the stat-grid that scrolls it', function () {
    /*
     * `overflow-x: auto` clips only descendants whose containing block is
     * inside the box. An `sr-only` span is `position: absolute`, so with no
     * positioned ancestor in between it escapes to the page at its column's
     * static x and widens the document — the picks grid's fifteenth column
     * took a 390px page to 693px. The utility carries `relative` so every
     * caller is covered, and no caller may take it back off.
     *
     * A browser check has to measure the document, never scroll it:
     * <html> is `motion-safe:scroll-smooth`, so `scrollTo({left: 999})`
     * leaves `scrollX` at 0 for a frame and passes a page that pans. Compare
     * `documentElement.scrollWidth` to `clientWidth` instead.
     */
    $css = file_get_contents(resource_path('css/app.css'));

    expect(preg_match('/@utility stat-grid \{(?<body>[^}]*)\}/', $css, $match))->toBe(1)
        ->and($match['body'])->toContain('position: relative;')
        ->and($match['body'])->toContain('overflow-x: auto;');

    $violations = [];

    foreach (bladeViews() as $path => $contents) {
        preg_match_all('/class="([^"]*\bstat-grid\b[^"]*)"/', $contents, $classes);

        foreach ($classes[1] as $class) {
            if (preg_match('/(?<![\w-])(static|absolute|fixed|sticky)(?![\w-])/', $class, $position)) {
                $violations[] = "{$path} [{$position[1]}]";
            }
        }
    }

    expect($violations)->toBe([], implode(', ', $violations)
        .' — repositions a stat-grid, so its sr-only text escapes the scroll'
        .' box and widens the page.');
});

it('maps the chart pair from its brand colors, and swaps in neutrals under dark', function () {
    /*
     * The marks read --chart-away / --chart-home. In light mode those come
     * from the brand pair the page sets inline; under .dark the utility
     * replaces them with the zinc/blue pair, the same un-branding as
     * team-accent. This is the stylesheet half; the sweep below is the page
     * half, and neither alone keeps dark mode neutral.
     */
    $css = file_get_contents(resource_path('css/app.css'));

    expect(preg_match('/@utility chart-pair \{(?<body>.*?)\n\}/s', $css, $utility))->toBe(1)
        ->and($utility['body'])->toContain('--chart-away: var(--chart-away-brand);')
        ->and($utility['body'])->toContain('--chart-home: var(--chart-home-brand);')
        ->and(preg_match('/\.dark & \{(?<dark>[^}]*)\}/', $utility['body'], $dark))->toBe(1)
        ->and($dark['dark'])->toContain('--chart-away: var(--color-zinc-400);')
        ->and($dark['dark'])->toContain('--chart-home: var(--color-blue-400);');
});

it('never sets a chart color inline, where it would beat the dark-mode swap', function () {
    /*
     * An inline declaration beats every stylesheet rule, so while the game
     * page's wrapper set --chart-away and --chart-home in its style
     * attribute, chart-pair's `.dark &` block never applied. Bucknell @ Pitt
     * drew navy rings and text on the near-black card, and the away "0.3%"
     * in the donut sat at roughly 1.1:1. The page sets
     * --chart-away-brand / --chart-home-brand instead and the utility maps
     * them, so the dark block has nothing inline to lose to.
     *
     * A feature test cannot compute a style, so this holds the source: no
     * Blade declares the names the marks read, and every chart-pair element
     * carries both brand colors, or light mode draws in nothing.
     */
    $inline = [];
    $unbranded = [];
    $callers = 0;

    foreach (bladeViews() as $path => $contents) {
        if (preg_match('/--chart-(away|home)\s*:/', $contents)) {
            $inline[] = $path;
        }

        // Quote-aware, because a Blade echo in an attribute carries `->`.
        preg_match_all('/<[\w:.-]+\s((?:[^>"]|"[^"]*")*)>/', $contents, $tags);

        foreach ($tags[1] as $attributes) {
            if (! preg_match('/\bclass="[^"]*(?<![\w-])chart-pair(?![\w-])[^"]*"/', $attributes)) {
                continue;
            }

            $callers++;

            preg_match('/\bstyle="([^"]*)"/', $attributes, $style);

            if (! str_contains($style[1] ?? '', '--chart-away-brand:') || ! str_contains($style[1] ?? '', '--chart-home-brand:')) {
                $unbranded[] = $path;
            }
        }
    }

    expect($callers)->toBeGreaterThan(0, 'no chart-pair element found — the sweep is vacuous')
        ->and($inline)->toBe([], implode(', ', $inline)
            .' — sets --chart-away/--chart-home directly, which beats chart-pair\'s'
            .' dark block. Set --chart-away-brand/--chart-home-brand instead.')
        ->and($unbranded)->toBe([], implode(', ', $unbranded)
            .' — a chart-pair element without both brand colors draws nothing in light mode.');
});

it('never clips a right-aligned flex row, which would cut its label from the left', function () {
    /*
     * `truncate` on a `justify-end` flex row clips the wrong end. The row's
     * text is an anonymous flex item, which cannot take an ellipsis, and
     * justify-end hands the overflow to the row's START — the game scorebug
     * rendered "7 PSU" as "SU" at 320. Put the text in its own `min-w-0
     * truncate` item instead: the row then never overflows, so there is
     * nothing for justify-end to push, and the cut lands at the end.
     */
    $violations = [];

    foreach (bladeViews() as $path => $contents) {
        preg_match_all('/class="([^"]*)"/', $contents, $classes);

        foreach ($classes[1] as $class) {
            if (preg_match('/(?<![\w-])justify-end(?![\w-])/', $class) && preg_match('/(?<![\w:-])truncate(?![\w-])/', $class)) {
                $violations[] = $path;
            }
        }
    }

    expect($violations)->toBe([], implode(', ', $violations)
        .' — truncates a justify-end row, which clips from the start.');
});

it('never lets a container query itself', function () {
    /*
     * A container query resolves against the nearest ANCESTOR container, so
     * an `@min-*` / `@max-*` variant on the `@container` element itself never
     * matches and fails silently — the scorebug row's gap stayed compact at
     * 390 until the container moved to a wrapper around it.
     */
    $violations = [];

    foreach (bladeViews() as $path => $contents) {
        preg_match_all('/class="([^"]*)"/', $contents, $classes);

        foreach ($classes[1] as $class) {
            if (preg_match('/(?<![\w-])@container(?![\w-])/', $class) && preg_match('/(?<![\w-])@(min|max)-/', $class)) {
                $violations[] = $path;
            }
        }
    }

    expect($violations)->toBe([], implode(', ', $violations)
        .' — queries its own @container, which never matches.');
});

it('renders the gutter track only through x-gutter-tabs', function () {
    /*
     * The zinc track with the raised active pad replaced the blue pill
     * strips. The one sanctioned segmented radio group is the appearance
     * switcher — it binds $flux.appearance through Alpine, which a wire:click
     * gutter cannot do, and the two render identically. It lives in exactly
     * ONE partial (rendered by Account and the avatar menu), so this
     * allowlist names one file and the control cannot drift between its two
     * homes by construction.
     */
    $violations = [];

    foreach (bladeViews() as $path => $contents) {
        if ($path === 'components/gutter-tabs.blade.php') {
            continue;
        }

        // The full pair, not the tint alone — the odds strip's
        // `dark:bg-zinc-800/50` contains it as a substring.
        if (str_contains($contents, 'bg-zinc-800/5 p-[3px]')) {
            $violations[] = $path;
        }

        if (str_contains($contents, 'variant="segmented"') && $path !== 'components/appearance-switcher.blade.php') {
            $violations[] = $path.' (segmented radio group)';
        }
    }

    expect($violations)->toBe([], implode(', ', $violations)
        .' — inlines the gutter markup. Use <x-gutter-tabs>.');
});

it('renders underlined tabs only through x-plate', function () {
    /*
     * Below `lg` the underline is exclusively the plate's in-content idiom —
     * a reader never has to ask whether an underlined row navigates or
     * filters. The one sanctioned exception is the section nav's `lg:`
     * restyle: at desktop widths the second header row wears the underline
     * to differentiate sections from the area chips beside the brand, and it
     * can, because it lives in the HEADER rather than in content. Any other
     * border-b-2 is still a regression to the two-idiom chrome.
     */
    $allowed = [
        'components/plate.blade.php',
        // The team page's sub nav owns the same idiom, because the plate
        // throws past three tabs and that screen has five. The two never
        // appear together: where the team nav rules a screen, the level
        // beneath it is pills (see the team page's stats toggle).
        'components/team-nav.blade.php',
        // The desktop restyle above — chrome may wear the underline at lg,
        // a control inside content still may not.
        'components/section-nav.blade.php',
    ];

    $violations = [];

    foreach (bladeViews() as $path => $contents) {
        if (str_contains($contents, 'border-b-2') && ! in_array($path, $allowed, true)) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([], implode(', ', $violations)
        .' — inlines an underlined tab strip. Use <x-plate>.');
});

it('renders no select boxes at all', function () {
    /*
     * Screen chrome is text-button dropdowns, full stop — a boxed select
     * sitting beside them was the last mixed idiom. Season, class and poll
     * all ride x-season-menu / x-filter-menu now, so a <flux:select>
     * anywhere in the views is a regression to the two-dialect chrome.
     */
    $violations = [];

    foreach (bladeViews() as $path => $contents) {
        // `(?=[\s>\/])` keeps a hypothetical <flux:selection> out of it.
        if (preg_match('/<flux:select(?=[\s>\/])/', $contents)) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([], implode(', ', $violations)
        .' — renders a select box. Use <x-season-menu> or <x-filter-menu>.');
});

it('speaks the merged idioms through their components, never inlined', function () {
    /*
     * The consolidation sweep: each of these patterns lives in exactly one
     * component now, and re-inlining it is how the copies drift apart —
     * or, for the clipboard, how the unguarded writeText that lied
     * "Copied" over a rejected promise comes back.
     */
    $banned = [
        'animate-pulse rounded-full bg-current' => [
            'components/live-dot.blade.php',
            '<x-live-dot />',
        ],
        'navigator.clipboard' => [
            null,
            'window.cfbClipboard.copy() — the guarded machine in app.js',
        ],
        'border-green-200 bg-green-50' => [
            'components/notice.blade.php',
            '<x-notice tone="success">',
        ],
        'stroke-dasharray="56.55"' => [
            'components/countdown-ring.blade.php',
            '<x-countdown-ring>',
        ],
        '>Preliminary</flux:badge>' => [
            'components/slate-status.blade.php',
            '<x-slate-status>',
        ],
        // The countdown has one home now. Two of them would be two
        // answers about the same kickoff — one formatting in the browser
        // and one on the server — and only the client-side copy is
        // invisible to the suite.
        'data-kick-at=' => [
            'components/kick-clock.blade.php',
            '<x-kick-clock>',
        ],
    ];

    $violations = [];

    foreach (bladeViews() as $path => $contents) {
        foreach ($banned as $pattern => [$home, $instead]) {
            if (str_contains($contents, $pattern) && $path !== $home) {
                $violations[] = "{$path} inlines [{$pattern}] — use {$instead}";
            }
        }
    }

    expect($violations)->toBe([], implode(' | ', $violations));
});
