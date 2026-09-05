<?php

use App\Support\ImageUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

/*
 * The Goalpost Salvage Co. clubhouse mark, pinned where it fails SILENTLY.
 *
 * Nothing in the app names this file: it is source art the commissioner
 * uploads through the group screen, and the only gate it ever meets is
 * SetGroupIcon's validation. A PNG that is too big, too small or the wrong
 * format is refused there with a Voice line — so the file the repository
 * ships is checked against that exact rule here, not against a copy of it.
 */
$png = public_path('brand/groups/goalpost-salvage-co.png');
$svg = public_path('brand/groups/goalpost-salvage-co.svg');

it('passes the group icon upload rule as shipped', function () use ($png) {
    /*
     * The real rule, through the real validator: `bail`, image, the four
     * servable formats, ImageUpload::MAX_KB and the 64px floor. Passing a
     * copy of the rule would pin the copy.
     */
    $file = new UploadedFile($png, 'goalpost-salvage-co.png', 'image/png', null, true);

    $validator = Validator::make(['iconFile' => $file], ['iconFile' => ImageUpload::rules()]);

    expect($validator->passes())->toBeTrue(implode(' ', $validator->errors()->all()));
});

it('is a 512px square under the upload cap', function () use ($png) {
    /*
     * Square, because x-group-icon crops with object-cover and a landscape
     * file would lose its uprights; 512, because the largest slot is 44px
     * on a 3x screen. The cap is the one number the browser and the server
     * both measure against.
     */
    [$width, $height] = getimagesize($png);

    expect($width)->toBe(512)
        ->and($height)->toBe(512)
        ->and(File::size($png))->toBeLessThanOrEqual(ImageUpload::MAX_KB * 1024);
});

it('was rasterized from the SVG beside it', function () use ($png) {
    /*
     * The PNG is generated FROM the SVG, and nothing else ties them: a
     * redraw that forgets to regenerate leaves the source and the upload
     * disagreeing, invisibly. Three flat regions, sampled well inside their
     * edges, carry the school's three colors and nothing off-palette —
     * Tennessee orange sky, Smokey water, white goalpost.
     */
    $image = imagecreatefrompng($png);
    $at = fn (int $x, int $y): string => sprintf('%06X', imagecolorat($image, $x, $y));

    expect($at(16, 16))->toBe('FF8200')
        ->and($at(496, 16))->toBe('FF8200')
        ->and($at(256, 496))->toBe('58595B')
        ->and($at(16, 496))->toBe('58595B')
        ->and($at(266, 266))->toBe('FFFFFF')
        ->and($at(357, 134))->toBe('FFFFFF');
});

it('keeps the source square and on the same three colors', function () use ($svg) {
    /*
     * The other half of the drift check: a redraw that recolors the SVG
     * and leaves the PNG alone passes the pixel samples above, because
     * the PNG has not changed. The source has to carry the same three
     * fills the PNG was sampled for, on a square canvas — object-cover
     * would crop anything else.
     */
    $source = File::get($svg);

    expect($source)
        ->toContain('viewBox="0 0 100 100"')
        ->toContain('#FF8200')
        ->toContain('#58595B')
        ->toContain('#FFFFFF');
});
