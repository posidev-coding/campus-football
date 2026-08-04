<?php

use App\Models\Team;
use App\Support\TeamPalette;

/**
 * The header palette is chosen by real WCAG contrast, and these tests assert
 * the RATIO rather than only which color won — a test that checked the choice
 * alone passed happily while Auburn rendered orange-on-navy at 4.2:1.
 */
const AA = 4.5;

function palette(string $color, ?string $alt = null): TeamPalette
{
    return TeamPalette::for(Team::factory()->make(['color' => $color, 'alt_color' => $alt]));
}

describe('the choice', function () {
    it('keeps the brand pairing when the secondary genuinely reads', function () {
        // Maize on navy is what Michigan actually looks like; no computed
        // black or white improves on it.
        $michigan = palette('00274c', 'ffcb05');

        expect($michigan->text)->toBe('#ffcb05')
            ->and(TeamPalette::contrast($michigan->text, $michigan->surface))->toBeGreaterThan(AA);
    });

    it('rejects a secondary that does not read, however far apart they look', function () {
        /*
         * Auburn, the reported bug. Navy and orange differ by 99.8 points of
         * YIQ brightness — past the old threshold of 90 — but only 4.2:1 of
         * actual contrast, while white on that navy is 11.6:1.
         */
        $auburn = palette('002b5c', 'f26522');

        expect(TeamPalette::contrast('#f26522', '#002b5c'))->toBeLessThan(AA)
            ->and($auburn->text)->toBe('#ffffff')
            ->and(TeamPalette::contrast($auburn->text, $auburn->surface))->toBeGreaterThan(10.0);
    });

    it('rejects a pure white secondary on a light primary', function () {
        // Tennessee. Its secondary IS white, which the brightness rule waved
        // through at 2.49:1 — the regression that shipped and was verified as
        // correct because the probe only checked which color was applied.
        $tennessee = palette('ff8200', 'ffffff');

        expect(TeamPalette::contrast('#ffffff', '#ff8200'))->toBeLessThan(3.0)
            ->and($tennessee->text)->toBe('#18181b')
            ->and(TeamPalette::contrast($tennessee->text, $tennessee->surface))->toBeGreaterThan(AA);
    });

    it('falls back to a neutral when the secondary is tone-on-tone', function () {
        // Georgia's secondary is near-black on red — barely a contrast at all.
        $georgia = palette('ba0c2f', '2c2a29');

        expect($georgia->text)->toBe('#ffffff');
    });

    it('reads every real FBS pairing at AA', function (string $team, string $color, ?string $alt) {
        $palette = palette($color, $alt);

        expect(TeamPalette::contrast($palette->text, $palette->surface))
            ->toBeGreaterThanOrEqual(AA, "{$team} is unreadable");
    })->with([
        ['Auburn', '002b5c', 'f26522'],
        ['Tennessee', 'ff8200', 'ffffff'],
        ['Michigan', '00274c', 'ffcb05'],
        ['Georgia', 'ba0c2f', '2c2a29'],
        ['LSU', '461d76', 'fdd023'],
        ['App State', '000000', 'ffcd00'],
        ['Navy', '00225b', 'b5a67c'],
        ['Arizona State', 'ffc627', '8c1d40'],
        ['Miami', 'f47423', '035131'],
        ['Ohio State', 'ba0c2f', 'a8adb4'],
        ['Clemson', 'f56600', 'ffffff'],
        ['Oregon', '00934b', 'fff41b'],
        ['Nebraska', 'e31937', 'ffffff'],
        ['North Carolina', '7bafd4', '13294b'],
    ]);
});

describe('the surface', function () {
    it('leaves a brand color untouched when something readable sits on it', function () {
        expect(palette('002b5c', 'f26522')->surface)->toBe('#002b5c')
            ->and(palette('00274c', 'ffcb05')->surface)->toBe('#00274c');
    });

    it('nudges only when no text color can be read on the brand color', function () {
        // Nebraska's mid-tone red: white and near-black both land under AA on
        // it, so the surface itself shifts until one clears.
        $nebraska = palette('e31937', 'ffffff');

        expect($nebraska->surface)->not->toBe('#e31937')
            ->and(TeamPalette::contrast($nebraska->text, $nebraska->surface))->toBeGreaterThanOrEqual(AA);
    });

    it('keeps a nudge small enough to read as the same color', function () {
        $nebraska = palette('e31937', 'ffffff');

        // Well inside a 12% shift on every channel.
        foreach ([[0xE3, 1], [0x19, 3], [0x37, 5]] as [$original, $offset]) {
            $shifted = hexdec(substr(ltrim($nebraska->surface, '#'), $offset - 1, 2));
            expect(abs($shifted - $original))->toBeLessThan(40);
        }
    });
});

describe('the gradient', function () {
    it('moves the far end away from the text, never blindly darker', function () {
        $lightText = palette('002b5c', 'f26522');   // white text on navy
        $darkText = palette('ff8200', 'ffffff');    // near-black text on orange

        $luminance = function (string $hex): float {
            $hex = ltrim($hex, '#');
            $channel = function (int $v): float {
                $c = $v / 255;

                return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * $channel((int) hexdec(substr($hex, 0, 2)))
                + 0.7152 * $channel((int) hexdec(substr($hex, 2, 2)))
                + 0.0722 * $channel((int) hexdec(substr($hex, 4, 2)));
        };

        // Light text: the far end darkens, as it always used to.
        expect($luminance($lightText->far))->toBeLessThan($luminance($lightText->surface));

        // Dark text: it LIGHTENS. Darkening here is what quietly made the far
        // end the worst case for every team with dark text.
        expect($luminance($darkText->far))->toBeGreaterThan($luminance($darkText->surface));
    });
});

describe('robustness', function () {
    it('returns null for a team with no usable color', function () {
        expect(TeamPalette::for(Team::factory()->make(['color' => null])))->toBeNull()
            ->and(TeamPalette::for(Team::factory()->make(['color' => 'xyzzy!'])))->toBeNull();
    });

    it('always finds a readable combination, for any color at all', function () {
        /*
         * The invariant, over generated input rather than today's 136 teams.
         * This is what proves the nudge loop terminates and the guarantee is
         * total — a table of real teams only ever proves the table.
         */
        mt_srand(20260804);

        for ($i = 0; $i < 400; $i++) {
            $color = sprintf('%06x', mt_rand(0, 0xFFFFFF));
            $alt = sprintf('%06x', mt_rand(0, 0xFFFFFF));

            $palette = palette($color, $alt);

            expect(TeamPalette::contrast($palette->text, $palette->surface))
                ->toBeGreaterThanOrEqual(AA, "#{$color} on #{$alt} came out unreadable");
        }
    });
});
