<?php

namespace App\Services\Contests;

use App\Models\Game;
use App\Models\Pick;
use App\Models\SlateGame;
use InvalidArgumentException;

/**
 * Pure against-the-spread arithmetic, shared by every mode: did the picked
 * side cover the FROZEN line?
 *
 * The magnitude of the spread is the favorite's burden and
 * `favorite_team_id` says who carries it — the sign is deliberately
 * ignored via abs(). Verified against live 2026 lines (2026-08-13): ESPN's
 * scoreboard spread is the HOME team's handicap — negative when home is
 * favored (Ole Miss at Oklahoma: -4.0, OU favored), POSITIVE when the road
 * team is (Navy at Army: +2.5, Navy favored) — so a grader that read the
 * sign as "the favorite's number" would silently flip every away-favorite
 * game. Magnitude plus favorite_team_id is invariant to that convention.
 * The favorite covers when it wins by MORE than the burden; landing
 * exactly on it is a push for both sides; anything else and the dog
 * covers, including winning outright.
 *
 * Every guard throws instead of guessing: grading an incomplete game, a
 * game with no frozen line, or a pick for a team that is not in the game
 * is corrupt state, and a wrong grade that looks right is the most
 * expensive bug this phase can ship.
 */
class SpreadGrader
{
    /**
     * @return string one of Pick::WIN | Pick::LOSS | Pick::PUSH
     */
    public function resultFor(SlateGame $slateGame, Game $game, int $pickedTeamId): string
    {
        // Live grading is the same math on the CURRENT score: from the
        // second a game kicks its picks are provisionally right or wrong,
        // recomputed on every score change and finalized at settlement.
        // Only a game that has not kicked has nothing to grade.
        if (! $game->hasKickedOff()) {
            throw new InvalidArgumentException("Game {$game->id} has not kicked off; there is nothing to grade.");
        }

        if ($slateGame->spread === null || $slateGame->favorite_team_id === null) {
            throw new InvalidArgumentException("Slate game {$slateGame->id} has no frozen line to grade against.");
        }

        $sides = [$game->home_team_id, $game->away_team_id];

        if (! in_array($slateGame->favorite_team_id, $sides, true)) {
            throw new InvalidArgumentException("Slate game {$slateGame->id}'s favorite is not in game {$game->id}.");
        }

        if (! in_array($pickedTeamId, $sides, true)) {
            throw new InvalidArgumentException("Picked team {$pickedTeamId} is not in game {$game->id}.");
        }

        $favoriteIsHome = $slateGame->favorite_team_id === $game->home_team_id;
        $favoriteMargin = $favoriteIsHome
            ? $game->home_score - $game->away_score
            : $game->away_score - $game->home_score;

        // Both operands are halves or wholes, which floats hold exactly —
        // no epsilon needed for a comparison of .5-grained numbers.
        $coverMargin = $favoriteMargin - abs($slateGame->spread);

        if ($coverMargin == 0) {
            return Pick::PUSH;
        }

        $favoriteCovered = $coverMargin > 0;
        $pickedFavorite = $pickedTeamId === $slateGame->favorite_team_id;

        return $favoriteCovered === $pickedFavorite ? Pick::WIN : Pick::LOSS;
    }
}
