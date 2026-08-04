<?php

namespace App\Support;

use App\Models\Team;

/**
 * The three colors a branded team surface needs, chosen by real contrast.
 *
 * This replaces a YIQ brightness rule that was not a contrast measure at all.
 * Brightness DIFFERENCE and contrast RATIO are different quantities: Auburn's
 * navy and orange differ by 99.8 points of YIQ brightness — comfortably past
 * the old threshold of 90 — while their actual WCAG ratio is 4.2:1, against
 * 11.6:1 for plain white on the same navy. Measured across all 136 FBS teams,
 * the old rule put text under 4.5:1 on 24 of them.
 *
 * Three values come out, and the caller sets all three as custom properties:
 *
 *   surface   the brand color, nudged ONLY if nothing else can be read on it
 *   text      the secondary color when it is genuinely readable, else white
 *             or near-black
 *   far       the gradient's far end, moved AWAY from the text
 *
 * That last one is why the gradient is safe. It used to darken unconditionally,
 * which quietly made the darkened end the worst case for dark text; moving it
 * away from whatever the text is means the pure brand color is the worst case
 * and the gradient can only ever help. That change alone rescues 17 of the 24.
 *
 * 129 of 136 teams keep their exact brand hex. The other seven have mid-tone
 * primaries — bright oranges, mid greens — where NO text color reaches the bar,
 * so the surface itself shifts by at most 12%, which reads as the same color.
 */
final class TeamPalette
{
    /**
     * WCAG AA for normal-size text. The binding constraint is the small
     * record/standing line, not the large team name, which would only need 3:1.
     */
    private const MIN_CONTRAST = 4.5;

    /**
     * Text on these surfaces renders at `opacity-90`, so contrast is measured
     * through that — scoring the opaque color overstates what a reader gets.
     */
    private const TEXT_OPACITY = 0.90;

    /** How far the gradient's far end travels away from the text. */
    private const GRADIENT_SHIFT = 0.22;

    /** Surface correction, stepped until the text clears MIN_CONTRAST. */
    private const NUDGE_STEP = 0.02;

    private const NUDGE_MAX_STEPS = 40;

    private const WHITE = '#ffffff';

    private const NEAR_BLACK = '#18181b';

    public function __construct(
        public readonly string $surface,
        public readonly string $far,
        public readonly string $text,
    ) {}

    /**
     * Null when the team has no usable color — callers then omit the custom
     * properties entirely and the neutral defaults in `:root` take over.
     */
    public static function for(Team $team): ?self
    {
        $primary = self::rgb($team->color);

        if ($primary === null) {
            return null;
        }

        $secondary = self::rgb($team->alt_color);

        [$surface, $text] = self::resolve($primary, $secondary);

        return new self(
            surface: self::hex($surface),
            far: self::hex(self::shiftAwayFrom($surface, $text, self::GRADIENT_SHIFT)),
            text: self::hex($text),
        );
    }

    /**
     * The WCAG 2.x contrast ratio between two colors, 1.0 to 21.0.
     *
     * Public because the tests assert through it: a test that only checked
     * WHICH color was chosen would have passed for Auburn's unreadable orange.
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
     * Pick the surface and the text together.
     *
     * The secondary color wins whenever it is genuinely readable, because the
     * school's own pairing beats anything computed — maize on Michigan navy is
     * what Michigan actually looks like. Only when it is not readable does this
     * fall back to a neutral, and only when NO candidate is readable does the
     * surface move.
     *
     * @param  array{int, int, int}  $primary
     * @param  array{int, int, int}|null  $secondary
     * @return array{array{int, int, int}, array{int, int, int}}
     */
    private static function resolve(array $primary, ?array $secondary): array
    {
        $surface = $primary;

        for ($step = 0; $step <= self::NUDGE_MAX_STEPS; $step++) {
            // Step 0 is the untouched brand color. Past that, try darker then
            // lighter at a widening distance and take the first that works.
            foreach ($step === 0 ? [$primary] : [
                self::mix($primary, [0, 0, 0], $step * self::NUDGE_STEP),
                self::mix($primary, [255, 255, 255], $step * self::NUDGE_STEP),
            ] as $candidate) {
                $text = self::bestTextOn($candidate, $secondary);

                if ($text !== null) {
                    return [$candidate, $text];
                }

                $surface = $candidate;
            }
        }

        // Unreachable in practice — pure black reads white at 21:1 — but a
        // loop that can exit without an answer must still return one.
        return [$surface, self::rgb(self::WHITE)];
    }

    /**
     * The best readable text for a surface, or null when nothing clears the bar.
     *
     * @param  array{int, int, int}  $surface
     * @param  array{int, int, int}|null  $secondary
     * @return array{int, int, int}|null
     */
    private static function bestTextOn(array $surface, ?array $secondary): ?array
    {
        if ($secondary !== null && self::readable($secondary, $surface)) {
            return $secondary;
        }

        $neutrals = [self::rgb(self::WHITE), self::rgb(self::NEAR_BLACK)];

        usort($neutrals, fn (array $a, array $b) => self::scoreOn($b, $surface) <=> self::scoreOn($a, $surface));

        return self::readable($neutrals[0], $surface) ? $neutrals[0] : null;
    }

    /**
     * @param  array{int, int, int}  $text
     * @param  array{int, int, int}  $surface
     */
    private static function readable(array $text, array $surface): bool
    {
        return self::scoreOn($text, $surface) >= self::MIN_CONTRAST;
    }

    /**
     * Contrast as actually rendered — through the text's own opacity.
     *
     * @param  array{int, int, int}  $text
     * @param  array{int, int, int}  $surface
     */
    private static function scoreOn(array $text, array $surface): float
    {
        return self::ratio(self::mix($surface, $text, self::TEXT_OPACITY), $surface);
    }

    /**
     * Move a color away from another one — toward black if the other is light,
     * toward white if it is dark.
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
     * approximation. The approximation is what this class exists to replace.
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
