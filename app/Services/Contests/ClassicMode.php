<?php

namespace App\Services\Contests;

use App\Models\SlateGame;

/**
 * Shotgun — the product name lives in ContestMode::label() and Voice;
 * this class and the stored value stay `classic`.
 *
 * Ten games, every one worth ten. The casual door — one decision per game
 * and nothing to weigh, which is exactly why it exists beside the tiered
 * main event. A perfect week is 100, the ceiling every mode's rebalance
 * aims at (the Woodshed's 101 is the founders' premium).
 */
class ClassicMode extends ModeEngine
{
    public const GAME_POINTS = 10;

    /**
     * The one mode that flexes: an untiered slate can be any length, which
     * is what the flash cards and the dynamic themed rooms ride. The tiered
     * modes deliberately ignore this knob — see their docblocks.
     */
    public function slateSize(): int
    {
        return (int) $this->setting('slate_size', 10);
    }

    public function tierSpec(): ?array
    {
        return null;
    }

    public function pointsFor(SlateGame $slateGame): int
    {
        return self::GAME_POINTS;
    }
}
