<?php

namespace App\Services\Contests;

use App\Models\Game;
use App\Models\Pick;
use App\Models\SlateGame;

/**
 * Grade every pick on one slate game against the CURRENT score — the one
 * grading loop, shared by the live recompute and official settlement so
 * the money math can never fork.
 *
 * Pure recompute, idempotent by construction: the result is a function of
 * (frozen line, current score, mode), so running twice writes the same
 * values and a corrected score simply regrades correctly on the next
 * pass. Rows are written only when they actually changed — the sync
 * discipline, because Saturday fires this on every scoring drive.
 */
class PickGrader
{
    public function __construct(private SpreadGrader $spreads) {}

    /**
     * @return int picks whose standing changed
     */
    public function gradeSlateGame(SlateGame $slateGame, Game $game): int
    {
        if (! $game->hasKickedOff()) {
            return 0;
        }

        // Eager callers (GradeGamePicks) arrive loaded; the fallback is
        // load-bearing — SettleSlate hands the grader its own slate games.
        $slateGame->loadMissing('slate.contest', 'picks');
        $engine = $slateGame->slate->contest->mode->engine($slateGame->slate->contest->settings);

        // Pin the LIVE game onto the slate game before pricing: the engine's
        // kicker arm reads $slateGame->game, and it must judge the same
        // score the result was computed from — never a stale re-query.
        $slateGame->setRelation('game', $game);

        $changed = 0;

        foreach ($slateGame->picks as $pick) {
            $result = $this->spreads->resultFor($slateGame, $game, $pick->picked_team_id);

            $pick->fill([
                'result' => $result,
                'points' => $engine->pointsForPick($slateGame, $pick, $result),
            ]);

            if ($pick->isDirty()) {
                $pick->save();
                $changed++;
            }
        }

        return $changed;
    }
}
