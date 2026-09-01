<?php

use Illuminate\Support\Facades\File;

/*
 * The currency mark, pinned where it fails SILENTLY.
 *
 * Every assertion here is a bug that shipped or nearly shipped in the redraw
 * (2026-08-31), and none of them is visible in the markup that uses the art:
 * a mis-sized mark still renders, a missing file still renders (as nothing),
 * and a stale PNG still opens.
 */
$svg = fn (string $name): string => public_path("brand/currency/svg/{$name}.svg");

$cuts = ['tallboy-light', 'tallboy-dark', 'tallboy-light-16', 'tallboy-dark-16'];

it('declares width and height on every cut, not just a viewBox', function (string $cut) use ($svg) {
    /*
     * THE LETTERBOX TRAP. An <img> gives an SVG carrying only a viewBox a
     * SQUARE intrinsic size, so the 42x100 can is scaled to fit the height and
     * centred in an 18x18 box with 42% of the width transparent — which is the
     * dead space the retired mark had and the redraw exists to remove.
     * Measured in Chrome: naturalWidth 150x150 without these attributes,
     * 42x100 with them.
     */
    $source = File::get($svg($cut));

    expect($source)
        ->toContain('width="42"')
        ->toContain('height="100"')
        ->toContain('viewBox="0 0 42 100"');
})->with($cuts);

it('uses no clipPath, so the PNGs cannot drift from the SVGs', function (string $cut) use ($svg) {
    /*
     * ImageMagick's SVG renderer ignores clipPath; the browser honours it. The
     * base rim carries its own rounded corners instead, so the generated PNG
     * family is the same picture the app draws rather than a near miss.
     */
    expect(File::get($svg($cut)))->not->toContain('clipPath');
})->with($cuts);

it('ships the light and dark cuts the mark component names', function () {
    /*
     * A missing icon is a 404 behind alt="", which renders as nothing and
     * reads as a layout choice. x-tallboy-mark is the ONE file that names
     * the art — every render site goes through it, so this is the only
     * place the filenames have to be checked.
     */
    $mark = File::get(resource_path('views/components/tallboy-mark.blade.php'));

    foreach (['tallboy-light-16', 'tallboy-dark-16'] as $named) {
        expect($mark)->toContain("brand/currency/svg/{$named}.svg")
            ->and(File::exists(public_path("brand/currency/svg/{$named}.svg")))->toBeTrue();
    }
});

it('keeps the art in one file', function () {
    /*
     * A seam with two copies is not a seam. Every render site asks
     * x-tallboy-mark; nothing else may name the asset path.
     */
    $namers = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => str_contains(File::get($file->getPathname()), 'brand/currency/svg/'))
        ->map(fn ($file) => $file->getFilename())
        ->values();

    expect($namers->all())->toBe(['tallboy-mark.blade.php']);
});

it('keeps the PNG family complete and in the can\'s aspect ratio', function () {
    /*
     * The PNGs are generated FROM the SVGs, so a redraw that forgets them
     * leaves the documented asset set showing the retired art. Height is the
     * documented size and width is 42% of it — a square PNG here means
     * something rasterized the old letterboxed shape.
     */
    foreach (['light', 'dark'] as $mode) {
        foreach ([16, 20, 24, 32, 48, 64, 128, 256] as $size) {
            $path = public_path("brand/currency/png/tallboy-{$mode}-{$size}.png");

            expect(File::exists($path))->toBeTrue("missing tallboy-{$mode}-{$size}.png");

            [$width, $height] = getimagesize($path);

            expect($height)->toBe($size)
                ->and($width)->toBe((int) round($size * 0.42));
        }
    }
});

it('retired the first pass along with the name', function () {
    /*
     * `latte-*` was kept for reference under a name that no longer exists.
     * Nothing referenced it, and a second silhouette in the folder is an
     * invitation to ship the wrong one.
     */
    expect(File::glob(public_path('brand/currency/*/*latte*')))->toBeEmpty()
        ->and(File::glob(public_path('brand/currency/*/*flat*')))->toBeEmpty();
});
