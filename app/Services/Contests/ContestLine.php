<?php

namespace App\Services\Contests;

use App\Models\Game;

/**
 * The league's HALF-POINT LAW, in one place.
 *
 * A contest line must never sit on a whole number — every pick succeeds or
 * fails, no pushes, no washes. The founders ran it that way on paper and it
 * is a hard product rule here: the book's number is where the line STARTS,
 * and the commissioner may move it up to MAX_ADJUSTMENT points either way,
 * but it always lands on a half point.
 *
 * Sign convention: contest lines are stored home-relative like the market's
 * (negative = home favored), but the sign is DERIVED from favorite_team_id
 * rather than trusted from the feed — the one honest source of who carries
 * the burden.
 */
class ContestLine
{
    /** How far the commissioner may move off the book, in points. */
    public const MAX_ADJUSTMENT = 3.0;

    /** The smallest burden a favorite can carry — never zero, never a tie. */
    public const MIN_BURDEN = 0.5;

    public static function isHalfPoint(float $spread): bool
    {
        // Half-grained decimals are exact in floats; the comparison is safe.
        return fmod(abs($spread), 1.0) === 0.5;
    }

    /**
     * The default contest burden for a market burden: already-half numbers
     * stand as the book set them; whole numbers shade DOWN a half point
     * (7 → 6.5) — an arbitrary-but-stable default the commissioner is one
     * tap from changing. Off-grid numbers snap to the half grid first.
     */
    public static function defaultBurden(float $marketBurden): float
    {
        $burden = round(abs($marketBurden) * 2) / 2;

        if (! self::isHalfPoint($burden)) {
            $burden = max(self::MIN_BURDEN, $burden - 0.5);
        }

        return $burden;
    }

    /**
     * The legal range for a contest burden set against a market burden:
     * within MAX_ADJUSTMENT either way, never below MIN_BURDEN — the
     * favorite never flips by adjustment.
     *
     * @return array{0: float, 1: float} [min, max]
     */
    public static function band(float $marketBurden): array
    {
        $market = abs($marketBurden);

        return [max(self::MIN_BURDEN, $market - self::MAX_ADJUSTMENT), $market + self::MAX_ADJUSTMENT];
    }

    public static function withinBand(float $burden, float $marketBurden): bool
    {
        [$min, $max] = self::band($marketBurden);

        return $burden >= $min && $burden <= $max;
    }

    /**
     * Home-relative signed spread for a burden, from the one honest source
     * of direction: who the favorite is.
     */
    public static function signed(float $burden, int $favoriteTeamId, Game $game): float
    {
        return $favoriteTeamId === $game->home_team_id ? -abs($burden) : abs($burden);
    }

    /**
     * Everything a slate game row is seeded with when a market line exists:
     * the half-pointed default contest line, the book's own number, and the
     * provenance of where both came from. Null when the book has nothing —
     * a pending row stays honestly empty.
     *
     * Callers should have `odds` loaded on the game.
     *
     * @return array{spread: float, market_spread: float, favorite_team_id: int, odds_provider: ?string, odds_captured_at: mixed}|null
     */
    public static function seedValues(Game $game): ?array
    {
        $current = GameQualityScore::usableCurrentOdd($game);

        if ($current === null) {
            return null;
        }

        $burden = self::defaultBurden((float) $current->spread);

        return [
            'spread' => self::signed($burden, $current->favorite_team_id, $game),
            'market_spread' => self::signed(abs((float) $current->spread), $current->favorite_team_id, $game),
            'favorite_team_id' => $current->favorite_team_id,
            'odds_provider' => $current->provider,
            'odds_captured_at' => $current->captured_at,
        ];
    }
}
