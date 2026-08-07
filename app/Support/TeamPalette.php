<?php

namespace App\Support;

use App\Enums\HeaderStyle;
use App\Models\Team;

/**
 * The colors a branded team header renders in — chosen brand-first, with
 * legibility as the floor rather than the target.
 *
 * This is the third pass at the rule, and each predecessor failed in an
 * instructive way. A YIQ brightness rule put Auburn's orange on navy at
 * 4.2:1 — brightness difference is not contrast. A strict WCAG-4.5 rule then
 * chose near-black on Tennessee orange — perfectly legible, and wrong to
 * every fan who has ever seen a jersey, because white-on-orange at 2.49:1 IS
 * Tennessee. No purely ratio-driven picker can produce the school's actual
 * branding, so the ladder starts from what sports branding actually does:
 *
 *   0. teams.header_style set          -> the admin picked; render that
 *   1. secondary vs primary >= 7.0     -> primary surface, SECONDARY text
 *                                         (Michigan maize, Colorado gold)
 *   2. white vs primary     >= 2.2     -> white text, the sports default,
 *                                         down to the mid-tone brands
 *                                         (Tennessee, Clemson, Miami)
 *   3. white vs secondary   >= 4.5     -> SECONDARY as the surface
 *                                         (Arizona State goes maroon)
 *   4. darken until white   >= 4.5     -> last resort; zero FBS teams today
 *
 * Rungs 2 and 3 were once separate: white above 4.5 rendered plain, and white
 * in the 2.2-4.5 band picked up a subtle dark text-shadow — the ESPN treatment
 * for mid-tone brands. They always chose the same COLORS and differed only in
 * that flourish, and the flourish is gone, so they are one rung. A header in
 * that band now renders flat white, which is what the jersey does.
 *
 * Near-black text exists only behind the explicit dark-text override — the
 * algorithm never chooses it.
 *
 * All of this is LIGHT MODE ONLY. In dark mode the chrome un-brands itself to
 * the neutral page surface (see the `.dark &` blocks in app.css), so the
 * palette never has to reconcile a brand color with a dark theme.
 */
final class TeamPalette
{
    /** A secondary must EARN text duty; weak pairings fall back to white. */
    private const SECONDARY_TEXT_MIN = 7.0;

    /** Comfortable white — what a surface must clear to be SWAPPED IN. */
    private const WHITE_COMFORT = 4.5;

    /** Below this a brand cannot honestly carry white at all. */
    private const WHITE_FLOOR = 2.2;

    /** Surface correction for the last-resort rung. */
    private const NUDGE_STEP = 0.02;

    private const NUDGE_MAX_STEPS = 50;

    private const WHITE = '#ffffff';

    private const NEAR_BLACK = '#18181b';

    public function __construct(
        public readonly string $surface,
        public readonly string $text,
    ) {}

    /**
     * Null when the team has no usable primary color — callers then omit the
     * custom properties and the neutral defaults in `:root` take over.
     */
    public static function for(Team $team): ?self
    {
        $primary = self::rgb($team->color);

        if ($primary === null) {
            return null;
        }

        $secondary = self::rgb($team->alt_color);

        $override = $team->header_style instanceof HeaderStyle
            ? $team->header_style
            : HeaderStyle::tryFrom((string) $team->header_style);

        return $override !== null
            ? self::fromOverride($override, $primary, $secondary)
            : self::fromLadder($primary, $secondary);
    }

    /** A data mark must be visible against the light page… */
    private const CHART_VS_PAGE_MIN = 2.0;

    /** …and the two marks must read as two colors, not one. */
    private const CHART_SEPARATION_MIN = 1.25;

    /**
     * The pair of colors a two-team chart draws in — LIGHT MODE ONLY, like
     * the rest of this class; dark mode un-brands to neutrals in CSS.
     *
     * Resolved as a PAIR, never per team, because the two failure modes are
     * both pairwise: a near-white brand vanishes into the page, and two red
     * teams become one ring. The away side keeps its primary; the home side
     * yields — first to its own secondary (Alabama's gray beside Georgia's
     * red is truer than a shifted red), then to a lightness shift, then to a
     * neutral that always reads.
     *
     * @return array{string, string} [away, home]
     */
    public static function chartColors(Team $away, Team $home): array
    {
        $first = self::chartable(self::rgb($away->color)) ?? self::rgb('#3f3f46');
        $second = self::chartable(self::rgb($home->color)) ?? self::rgb('#71717a');

        if (self::ratio($first, $second) < self::CHART_SEPARATION_MIN) {
            $second = self::separate($second, $first, self::rgb($home->alt_color));
        }

        return [self::hex($first), self::hex($second)];
    }

    /**
     * Darken a too-pale mark until it is visible on the page. Null stays
     * null so the caller can substitute its fallback.
     *
     * @param  array{int, int, int}|null  $color
     * @return array{int, int, int}|null
     */
    private static function chartable(?array $color): ?array
    {
        if ($color === null) {
            return null;
        }

        $white = self::rgb(self::WHITE);

        for ($step = 0; $step <= self::NUDGE_MAX_STEPS; $step++) {
            $candidate = self::mix($color, [0, 0, 0], $step * self::NUDGE_STEP);

            if (self::ratio($white, $candidate) >= self::CHART_VS_PAGE_MIN) {
                return $candidate;
            }
        }

        return $color;
    }

    /**
     * Make one mark distinguishable from another it currently matches.
     *
     * @param  array{int, int, int}  $color
     * @param  array{int, int, int}  $against
     * @param  array{int, int, int}|null  $secondary
     * @return array{int, int, int}
     */
    private static function separate(array $color, array $against, ?array $secondary): array
    {
        $white = self::rgb(self::WHITE);

        $alt = self::chartable($secondary);

        if ($alt !== null && self::ratio($alt, $against) >= self::CHART_SEPARATION_MIN) {
            return $alt;
        }

        // Shift toward whichever pole is farther from the color it matches,
        // stopping at the first step that both separates and stays visible.
        $target = self::luminance($against) > 0.5 ? [0, 0, 0] : [255, 255, 255];

        for ($step = 1; $step <= self::NUDGE_MAX_STEPS; $step++) {
            $candidate = self::mix($color, $target, $step * self::NUDGE_STEP);

            if (self::ratio($candidate, $against) >= self::CHART_SEPARATION_MIN
                && self::ratio($white, $candidate) >= self::CHART_VS_PAGE_MIN) {
                return $candidate;
            }
        }

        // Two colors that cannot be pulled apart get a neutral that always
        // reads — zinc-500 on white is ~4.6:1 and unlike any brand red.
        return self::rgb('#71717a');
    }

    /**
     * The WCAG 2.x contrast ratio between two colors, 1.0 to 21.0.
     *
     * Public because the tests assert through it: a test that only checked
     * WHICH color was chosen has already let one unreadable header ship.
     */
    public static function contrast(string $a, string $b): float
    {
        $first = self::rgb($a);
        $second = self::rgb($b);

        if ($first === null || $second === null) {
            return 1.0;
        }

        return self::ratio($first, $second);
    }

    /**
     * @param  array{int, int, int}  $primary
     * @param  array{int, int, int}|null  $secondary
     */
    private static function fromLadder(array $primary, ?array $secondary): self
    {
        $white = self::rgb(self::WHITE);

        if ($secondary !== null && self::ratio($secondary, $primary) >= self::SECONDARY_TEXT_MIN) {
            return self::assemble($primary, $secondary);
        }

        if (self::ratio($white, $primary) >= self::WHITE_FLOOR) {
            return self::assemble($primary, $white);
        }

        if ($secondary !== null && self::ratio($white, $secondary) >= self::WHITE_COMFORT) {
            return self::assemble($secondary, $white);
        }

        // Last resort: walk the primary darker until white reads. Terminates
        // by construction — white on pure black is 21:1.
        $surface = $primary;

        for ($step = 1; $step <= self::NUDGE_MAX_STEPS; $step++) {
            $surface = self::mix($primary, [0, 0, 0], $step * self::NUDGE_STEP);

            if (self::ratio($white, $surface) >= self::WHITE_COMFORT) {
                break;
            }
        }

        return self::assemble($surface, $white);
    }

    /**
     * An admin's preset, rendered without judgement — except that a preset
     * needing a secondary falls back to the ladder when the team has none.
     *
     * @param  array{int, int, int}  $primary
     * @param  array{int, int, int}|null  $secondary
     */
    private static function fromOverride(HeaderStyle $style, array $primary, ?array $secondary): self
    {
        $white = self::rgb(self::WHITE);

        return match ($style) {
            HeaderStyle::White => self::assemble($primary, $white),
            HeaderStyle::SecondaryText => $secondary !== null
                ? self::assemble($primary, $secondary)
                : self::fromLadder($primary, null),
            HeaderStyle::SecondarySurface => $secondary !== null
                ? self::assemble($secondary, $white)
                : self::fromLadder($primary, null),
            HeaderStyle::DarkText => self::assemble($primary, self::rgb(self::NEAR_BLACK)),
        };
    }

    /**
     * @param  array{int, int, int}  $surface
     * @param  array{int, int, int}  $text
     */
    private static function assemble(array $surface, array $text): self
    {
        return new self(
            surface: self::hex($surface),
            text: self::hex($text),
        );
    }

    /**
     * @param  array{int, int, int}  $from
     * @param  array{int, int, int}  $to
     * @return array{int, int, int}
     */
    private static function mix(array $from, array $to, float $amount): array
    {
        $amount = max(0.0, min(1.0, $amount));

        return [
            (int) round($from[0] + ($to[0] - $from[0]) * $amount),
            (int) round($from[1] + ($to[1] - $from[1]) * $amount),
            (int) round($from[2] + ($to[2] - $from[2]) * $amount),
        ];
    }

    /**
     * @param  array{int, int, int}  $a
     * @param  array{int, int, int}  $b
     */
    private static function ratio(array $a, array $b): float
    {
        $first = self::luminance($a);
        $second = self::luminance($b);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    /**
     * WCAG relative luminance — the real sRGB gamma curve, not a YIQ
     * approximation. The approximation is what the first version of this rule
     * shipped with, and why Auburn was unreadable.
     *
     * @param  array{int, int, int}  $rgb
     */
    private static function luminance(array $rgb): float
    {
        $channel = function (int $value): float {
            $c = $value / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0])
            + 0.7152 * $channel($rgb[1])
            + 0.0722 * $channel($rgb[2]);
    }

    /**
     * @return array{int, int, int}|null
     */
    private static function rgb(?string $color): ?array
    {
        $hex = ltrim((string) $color, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param  array{int, int, int}  $rgb
     */
    private static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }
}
