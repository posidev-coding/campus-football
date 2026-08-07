<?php

use App\Enums\HeaderStyle;
use App\Models\Team;
use App\Support\TeamPalette;

/**
 * Brand-first, legibility as the floor. These tests assert RATIOS as well as
 * choices — a test that only checked which color won has already let one
 * unreadable header ship — and they name the two teams whose headers went
 * wrong in earlier versions of this rule, so neither can regress quietly.
 */
const AA = 4.5;
const WHITE_FLOOR = 2.2;

function palette(string $color, ?string $alt = null, ?HeaderStyle $style = null): TeamPalette
{
    return TeamPalette::for(Team::factory()->make([
        'color' => $color,
        'alt_color' => $alt,
        'header_style' => $style,
    ]));
}

describe('the ladder', function () {
    it('gives Tennessee white on orange — never dark text', function () {
        /*
         * The case this rework exists for. White on Tennessee orange is
         * 2.49:1, below every WCAG bar — and it is Tennessee, on every
         * jersey. A strict 4.5 rule chose near-black here: legible, and
         * wrong to every fan. White stays, flat: the band between the floor
         * and comfort once carried a text-shadow and no longer does.
         */
        $tennessee = palette('ff8200', 'ffffff');

        expect($tennessee->text)->toBe('#ffffff')
            ->and($tennessee->surface)->toBe('#ff8200')
            ->and(TeamPalette::contrast('#ffffff', '#ff8200'))->toBeGreaterThan(WHITE_FLOOR);
    });

    it('gives Auburn white on navy — never its 4.2:1 orange', function () {
        // The other named regression: a brightness rule chose the secondary
        // here because the two colors LOOK far apart.
        $auburn = palette('002b5c', 'f26522');

        expect(TeamPalette::contrast('#f26522', '#002b5c'))->toBeLessThan(7.0)
            ->and($auburn->text)->toBe('#ffffff')
            ->and(TeamPalette::contrast($auburn->text, $auburn->surface))->toBeGreaterThan(10.0);
    });

    it('keeps a secondary as text only when it EARNS it at 7:1', function () {
        // Michigan maize on navy: 9.9:1, comfortably on-brand.
        $michigan = palette('00274c', 'ffcb05');

        expect($michigan->text)->toBe('#ffcb05')
            ->and(TeamPalette::contrast($michigan->text, $michigan->surface))->toBeGreaterThan(7.0);

        // Colorado gold-and-black IS black text on a gold surface.
        expect(palette('cfb87c', '000000')->text)->toBe('#000000');
    });

    it('swaps to the secondary as SURFACE when the primary is too light for white', function () {
        // Arizona State: white dies on maize (1.57:1), so the header goes
        // maroon — which is what ASU's own web headers do.
        $asu = palette('ffc627', '8c1d40');

        expect($asu->surface)->toBe('#8c1d40')
            ->and($asu->text)->toBe('#ffffff')
            ->and(TeamPalette::contrast($asu->text, $asu->surface))->toBeGreaterThanOrEqual(AA);
    });

    it('darkens the primary as the last resort', function () {
        // A light primary and no usable secondary: nothing readable exists,
        // so the surface walks darker until white clears. No FBS team needs
        // this today; FCS and Division II colors are not so tidy.
        $palette = palette('ffc627', null);

        expect($palette->text)->toBe('#ffffff')
            ->and($palette->surface)->not->toBe('#ffc627')
            ->and(TeamPalette::contrast($palette->text, $palette->surface))->toBeGreaterThanOrEqual(AA);
    });

    it('never chooses dark text on its own', function () {
        // Near-black exists only behind the explicit override. Sweep a spread
        // of primaries; none may come out with dark text unless the SECONDARY
        // is itself dark (the Colorado case), which is a brand choice.
        foreach (['ff8200', 'ffc627', 'b3a369', '7bafd4', 'e31937', 'f56600'] as $primary) {
            expect(palette($primary, null)->text)->toBe('#ffffff', "#{$primary} chose non-white text");
        }
    });
});

describe('the override', function () {
    it('renders each admin preset', function () {
        expect(palette('00274c', 'ffcb05', HeaderStyle::White)->text)->toBe('#ffffff')
            ->and(palette('002b5c', 'f26522', HeaderStyle::SecondaryText)->text)->toBe('#f26522')
            ->and(palette('ffc627', '8c1d40', HeaderStyle::SecondarySurface)->surface)->toBe('#8c1d40')
            ->and(palette('ff8200', 'ffffff', HeaderStyle::DarkText)->text)->toBe('#18181b');
    });

    it('honours a preset the ladder would not have chosen', function () {
        // The whole point of the override: white on Tennessee orange is
        // 2.49:1 and renders anyway, because an admin asked for it. A preset
        // cannot be configured unreadable, but it can be configured brave.
        $forced = palette('ff8200', 'ffffff', HeaderStyle::White);

        expect($forced->text)->toBe('#ffffff')
            ->and($forced->surface)->toBe('#ff8200');
    });

    it('falls back to the ladder when a preset needs a secondary the team lacks', function () {
        expect(palette('002b5c', null, HeaderStyle::SecondaryText)->text)->toBe('#ffffff');
    });
});

describe('the surface', function () {
    it('is the brand color FLAT, with no second color derived from it', function () {
        /*
         * The header carried a 115deg gradient to the primary shifted 22%
         * away from the text. It read as a shadow falling across the header
         * rather than as depth — the failure mode of any gradient subtle
         * enough to be tasteful. A palette now describes two colors and only
         * two, so nothing can reintroduce a second surface tone by accident.
         */
        $palette = palette('ff8200', 'ffffff');

        expect($palette->surface)->toBe('#ff8200')
            ->and(get_object_vars($palette))->toBe([
                'surface' => '#ff8200',
                'text' => '#ffffff',
            ]);
    });
});

describe('robustness', function () {
    it('returns null for a team with no usable color', function () {
        expect(TeamPalette::for(Team::factory()->make(['color' => null])))->toBeNull()
            ->and(TeamPalette::for(Team::factory()->make(['color' => 'xyzzy!'])))->toBeNull();
    });

    it('always lands readable, or white no worse than the floor', function () {
        /*
         * The invariant over generated input: every outcome either clears AA
         * outright, or is WHITE and no worse than 2.2 — the band the sport's
         * own mid-tone brands live in. Nothing may land below the floor in
         * any color. A table of real teams only ever proves the table.
         */
        mt_srand(20260804);

        for ($i = 0; $i < 400; $i++) {
            $color = sprintf('%06x', mt_rand(0, 0xFFFFFF));
            $alt = sprintf('%06x', mt_rand(0, 0xFFFFFF));

            $palette = palette($color, $alt);
            $ratio = TeamPalette::contrast($palette->text, $palette->surface);

            if ($ratio < AA) {
                expect($palette->text)->toBe('#ffffff', "#{$color}/#{$alt} put a non-white text below AA")
                    ->and($ratio)->toBeGreaterThanOrEqual(WHITE_FLOOR, "#{$color}/#{$alt} landed below the floor");
            }
        }
    });
});
