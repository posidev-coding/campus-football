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

    foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
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

it('renders the gutter track only through x-gutter-tabs', function () {
    /*
     * The zinc track with the raised active pad replaced the blue pill
     * strips. Account keeps Flux\'s own segmented radio group for the
     * appearance toggle — it binds $flux.appearance through Alpine, which a
     * wire:click gutter cannot do, and the two render identically.
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

        if (str_contains($contents, 'variant="segmented"') && $path !== 'livewire/account.blade.php') {
            $violations[] = $path.' (segmented radio group)';
        }
    }

    expect($violations)->toBe([], implode(', ', $violations)
        .' — inlines the gutter markup. Use <x-gutter-tabs>.');
});

it('renders underlined tabs only through x-plate', function () {
    /*
     * The section nav used to share these classes byte-for-byte; it speaks
     * the area nav's chip language now, so the underline is exclusively the
     * plate's in-content idiom — a reader never has to ask whether an
     * underlined row navigates or filters. A border-b-2 reappearing in
     * section-nav is a regression to the two-idiom chrome.
     */
    $allowed = [
        'components/plate.blade.php',
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
