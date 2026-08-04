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
 *   2. white vs primary     >= 4.5     -> white text, the sports default
 *   3. white vs primary     >= 2.2     -> white text plus a subtle dark
 *                                         shadow — the ESPN treatment for
 *                                         mid-tone brands like Tennessee
 *   4. white vs secondary   >= 4.5     -> SECONDARY as the surface
 *                                         (Arizona State goes maroon)
 *   5. darken until white   >= 4.5     -> last resort; zero FBS teams today
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

    /** Comfortable white — no help needed. */
    private const WHITE_COMFORT = 4.5;

    /** Below this even the shadow treatment cannot honestly carry white. */
    private const WHITE_FLOOR = 2.2;

    /** How far the gradient's far end travels away from the text. */
    private const GRADIENT_SHIFT = 0.22;

    /** Surface correction for the last-resort rung. */
    private const NUDGE_STEP = 0.02;

    private const NUDGE_MAX_STEPS = 50;

    private const WHITE = '#ffffff';

    private const NEAR_BLACK = '#18181b';

    public function __construct(
        public readonly string $surface,
        public readonly string $far,
        public readonly string $text,
        public readonly bool $shadow = false,
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

        $whiteOnPrimary = self::ratio($white, $primary);

        if ($whiteOnPrimary >= self::WHITE_COMFORT) {
            return self::assemble($primary, $white);
        }

        if ($whiteOnPrimary >= self::WHITE_FLOOR) {
            return self::assemble($primary, $white, shadow: true);
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
            HeaderStyle::White => self::assemble(
                $primary,
                $white,
                shadow: self::ratio($white, $primary) < self::WHITE_COMFORT,
            ),
            HeaderStyle::SecondaryText => $secondary !== null
                ? self::assemble($primary, $secondary)
                : self::fromLadder($primary, null),
            HeaderStyle::SecondarySurface => $secondary !== null
                ? self::assemble($secondary, $white, shadow: self::ratio($white, $secondary) < self::WHITE_COMFORT)
                : self::fromLadder($primary, null),
            HeaderStyle::DarkText => self::assemble($primary, self::rgb(self::NEAR_BLACK)),
        };
    }

    /**
     * @param  array{int, int, int}  $surface
     * @param  array{int, int, int}  $text
     */
    private static function assemble(array $surface, array $text, bool $shadow = false): self
    {
        return new self(
            surface: self::hex($surface),
            far: self::hex(self::shiftAwayFrom($surface, $text, self::GRADIENT_SHIFT)),
            text: self::hex($text),
            shadow: $shadow,
        );
    }

    /**
     * Move a color away from another one — toward black if the other is light,
     * toward white if it is dark. This is why the gradient can only ever help:
     * the pure brand color is always its worst case.
     *
     * @param  array{int, int, int}  $color
     * @param  array{int, int, int}  $from
     * @return array{int, int, int}
     */
    private static function shiftAwayFrom(array $color, array $from, float $amount): array
    {
        $target = self::luminance($from) > self::luminance($color)
            ? [0, 0, 0]
            : [255, 255, 255];

        return self::mix($color, $target, $amount);
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
