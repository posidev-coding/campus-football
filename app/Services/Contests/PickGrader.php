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

        $slateGame->loadMissing('slate.contest');
        $engine = $slateGame->slate->contest->mode->engine($slateGame->slate->contest->settings);

        $changed = 0;

        foreach ($slateGame->picks()->get() as $pick) {
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
