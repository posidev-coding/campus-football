<?php

namespace App\Actions;

use App\Enums\TiebreakerMetric;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\User;
use App\Support\SlateAuthority;
use InvalidArgumentException;

/**
 * Designate the board's tiebreaker: which game, what QUESTION about it,
 * and — for one-sided metrics — whose number. The paper league rotated
 * its criterion week to week; the metric enum is that tradition, and
 * settlement resolves the answer from data the app already holds.
 *
 * Required before publish; the engine enforces that, this points the
 * finger and phrases the question.
 */
class SetTiebreaker
{
    public function handle(
        User $actor,
        Slate $slate,
        SlateGame $slateGame,
        TiebreakerMetric $metric = TiebreakerMetric::CombinedPoints,
        ?int $teamId = null,
    ): void {
        SlateAuthority::commissioner($actor, $slate);
        SlateAuthority::draft($slate);
        SlateAuthority::onSlate($slate, $slateGame);

        $slateGame->loadMissing('game');
        $sides = [$slateGame->game->home_team_id, $slateGame->game->away_team_id];

        if ($metric->needsTeam()) {
            if (! in_array($teamId, $sides, true)) {
                throw new InvalidArgumentException("Team {$teamId} is not in the tiebreaker game.");
            }
        } else {
            // A team on a whole-game question is stale state waiting to
            // confuse a settlement — never stored.
            $teamId = null;
        }

        $slate->update([
            'tiebreaker_slate_game_id' => $slateGame->id,
            'tiebreaker_metric' => $metric,
            'tiebreaker_team_id' => $teamId,
        ]);
    }
}
