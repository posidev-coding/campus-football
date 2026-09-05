<?php

use App\Support\ImageUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

/*
 * The Hopefuls' clubhouse mark, pinned where it fails SILENTLY.
 *
 * Nothing in the app names this art. It reaches the group the way every
 * clubhouse icon does — the commissioner's upload on the group screen — so
 * the one thing that matters is that the shipped cut clears that gate. A cut
 * the gate refuses is art that never shipped, and a raster that came out
 * blank passes every size check while showing a navy square.
 */
$svg = public_path('brand/groups/the-hopefuls.svg');
$png = public_path('brand/groups/the-hopefuls-512.png');

it('ships a cut the commissioner upload gate accepts', function () use ($png) {
    /*
     * The rules the group screen validates an upload against — the four
     * formats, MAX_KB, the 64px floor — run against the real file rather
     * than a description of it. The source is drawn as an SVG, which
     * `mimes` refuses, so this is also the assertion that stops someone
     * "just uploading the SVG".
     */
    $upload = new UploadedFile($png, 'the-hopefuls-512.png', 'image/png', null, true);

    $validator = Validator::make(['iconFile' => $upload], ['iconFile' => ImageUpload::rules()]);

    expect($validator->passes())->toBeTrue(implode(' ', $validator->errors()->all()));
});

it('is a square 512px raster of the mark, in the colors the source declares', function () use ($svg, $png) {
    /*
     * A rasterizer handed an SVG it could not parse writes a perfectly valid
     * PNG of nothing, and every other check here passes it. One pixel per
     * color — ground, a cream letter stroke, a lager foot — at the points
     * the source puts them, plus the horizon gap, which is ground again and
     * proves the split is there. The set of colors found must be the set of
     * fills the source declares, so a recolor that forgets to regenerate the
     * PNG reds too.
     */
    [$width, $height, $type] = getimagesize($png);

    expect($width)->toBe(512)
        ->and($height)->toBe(512)
        ->and($type)->toBe(IMAGETYPE_PNG);

    $image = imagecreatefrompng($png);

    $at = fn (int $x, int $y): string => sprintf('#%06X', imagecolorat($image, $x, $y) & 0xFFFFFF);

    expect($at(16, 16))->toBe('#0F1A2E', 'ground')
        ->and($at(151, 148))->toBe('#F5F2EA', 'the T bar')
        ->and($at(353, 251))->toBe('#F5F2EA', 'the H crossbar')
        ->and($at(151, 351))->toBe('#E8A33C', 'the T foot')
        ->and($at(412, 351))->toBe('#E8A33C', 'the H foot')
        ->and($at(151, 305))->toBe('#0F1A2E', 'the horizon gap');

    preg_match_all('/fill="(#[0-9A-F]{6})"/', File::get($svg), $fills);

    expect(collect($fills[1])->unique()->sort()->values()->all())
        ->toBe(['#0F1A2E', '#E8A33C', '#F5F2EA']);
});

it('declares width and height on the source, not just a viewBox', function () use ($svg) {
    /*
     * THE LETTERBOX TRAP, the same one the currency mark documents: an <img>
     * gives an SVG carrying only a viewBox a square intrinsic size. This
     * mark IS square, so it would survive today — the attributes are pinned
     * so the next cut drawn from this file inherits them.
     */
    expect(File::get($svg))
        ->toContain('width="100"')
        ->toContain('height="100"')
        ->toContain('viewBox="0 0 100 100"')
        ->not->toContain('clipPath');
});
