<?php

namespace App\Enums;

use App\Support\Navigation;

/**
 * The five areas of the app, as a bitmask — one `user_days.areas` column
 * answering "how much of the product did this person actually touch today".
 *
 * A bitmask rather than five columns or a join table because the question is
 * always asked of a set: breadth per day, and how breadth moves over a
 * cohort's first month. Five bits in a tinyint, and `areas & 4` is the whole
 * query.
 *
 * The values match the bottom tab bar because that is the app's own model of
 * where you are, and the route to area mapping is READ FROM
 * `Navigation::areas()` rather than copied here. A second map is a map that
 * drifts: a route added to the nav next month would keep lighting its tab and
 * silently stop counting, and nothing would fail.
 */
enum ActivityArea: int
{
    case Home = 1;

    case Scores = 2;

    case Picks = 4;

    case League = 8;

    case Account = 16;

    /**
     * The area a route name belongs to, or NULL when the nav does not claim
     * it.
     *
     * Null is the important half. A route the nav has never heard of — a
     * legacy redirect, an auth screen, something added without a tab — is
     * NOT Home; falling back to the first case would quietly inflate the one
     * area every acquisition question is read against, and it would inflate
     * it by exactly the routes nobody remembered to classify. No data means
     * the caller skips the bit.
     *
     * Only the area's own `routes` list is read, never its `sections` —
     * deliberately, even though the Picks strip lists routes the area does
     * not (`pickem.talk` today). Sections are CONDITIONAL: the Picks strip
     * appears only inside the pick'em flag's config mirror or for an admin,
     * and Account's only for a signed-in reader. A rollup runs in the
     * console, hours after the fact, with nobody signed in — so reading them
     * would classify the same route differently depending on which flags
     * happened to be on when the rollup ran, and yesterday's breadth would
     * stop matching last week's. The outer list is unconditional, and it is
     * the same list `currentArea()` lights the tab bar from.
     */
    public static function forRoute(?string $route): ?self
    {
        if ($route === null || $route === '') {
            return null;
        }

        foreach (Navigation::areas() as $area) {
            if (in_array($route, $area['routes'], true)) {
                return self::fromKey($area['key']);
            }
        }

        return null;
    }

    /** Is this area's bit set in a stored mask? */
    public function in(int $mask): bool
    {
        return ($mask & $this->value) !== 0;
    }

    /**
     * A nav key to a case. An unknown key is null rather than a default, for
     * the reason `forRoute()` gives: a sixth area added to the nav must read
     * as unclassified until somebody adds its bit, not as Home.
     */
    private static function fromKey(string $key): ?self
    {
        return match ($key) {
            'home' => self::Home,
            'scores' => self::Scores,
            'picks' => self::Picks,
            'league' => self::League,
            'account' => self::Account,
            default => null,
        };
    }
}
