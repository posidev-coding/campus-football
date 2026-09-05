<?php

namespace App\Support;

use App\Models\Game;
use Illuminate\Support\Collection;

/**
 * The order a board of games reads in: what is on NOW, then what is on NEXT,
 * then what already happened.
 *
 * The scoreboard ordered purely by `kickoff_at`, which is chronology, not
 * urgency — so on a Saturday afternoon a game in the fourth quarter sat
 * underneath every noon final that had already been decided. A reader
 * checking the board mid-afternoon had to scroll past settled results to
 * find the one thing actually happening.
 *
 * LIVE, then UPCOMING, then FINAL — ESPN's scoreboard shape, and the one a
 * Saturday reader is served by. The two live states are the ones with a
 * clock on them: "what is on" and "what is on next" are both decisions the
 * reader can still act on, while a final is an archive that will read the
 * same tomorrow. Sinking finals below kickoffs is what keeps the top of the
 * day the part that is still moving.
 *
 * The rank is deliberately three-way rather than the two-way live-vs-not that
 * Picks' hero ordering uses (`byUrgency()` in pickem-home): that surface is
 * choosing ONE card, so it only needs to know which is most urgent. A board
 * shows all of them at once and has to say where the settled half goes.
 *
 * WITHIN A DAY, never across a week. The scoreboard's structure is its
 * sticky day headings, and lifting a live Saturday game above Friday's
 * heading would strand it under the wrong date — the same mistake as losing
 * the date off a pinned followed-team group. In practice this costs nothing
 * on the day it matters: a Saturday's games are one day group.
 */
class GameOrder
{
    private const LIVE = 0;

    private const UPCOMING = 1;

    private const FINAL = 2;

    /**
     * Which of the three bands a game sits in.
     *
     * `isInProgress()` is the app's one definition of live and covers
     * halftime and end-of-period as well as `in` — a game at the half is
     * still a game somebody is watching, and burying it under the finals
     * for fifteen minutes is the bug in miniature.
     *
     * Everything that is neither live nor complete is UPCOMING, including a
     * game sitting at `pre` past its kickoff because the feed has not caught
     * up, and a postponed one. Both are honestly "not played yet", which is
     * where a reader looks for them.
     */
    public static function rank(Game $game): int
    {
        if ($game->isInProgress()) {
            return self::LIVE;
        }

        return $game->completed ? self::FINAL : self::UPCOMING;
    }

    /**
     * Stratify one day's games without disturbing the chronology inside each
     * band.
     *
     * The incoming order IS the tiebreaker: callers hand this a set already
     * ordered by `kickoff_at`, and PHP's sort has been stable since 8.0, so
     * two games in the same band come out in the order they arrived. That is
     * the point — this only lifts bands, it never re-sorts within one, so a
     * null or duplicated kickoff cannot be reordered into nonsense by a
     * comparison this class had no business making.
     *
     * @param  Collection<int, Game>  $games
     * @return Collection<int, Game>
     */
    public static function liveFirst(Collection $games): Collection
    {
        return $games->sortBy(fn (Game $game): int => self::rank($game))->values();
    }
}
