<?php

namespace App\Services\Contests;

use App\Models\SlateGame;

/**
 * Triple Option — the product name lives in ContestMode::label() and Voice;
 * this class and the stored value stay `tiered`.
 *
 * Fifteen games in three tiers of five, by game quality: tier 1 pays 9,
 * tier 2 pays 7, tier 3 pays 4 — settled 2026-08-14 so a perfect week is
 * exactly 100, level with Shotgun's ceiling. (The proposed tier names —
 * the Pitch, the Keep, the Dive — are screen vocabulary, not engine
 * facts.) A league wanting its own numbers is `$settings`' job someday —
 * the constructor already carries the seam.
 */
class TieredMode extends ModeEngine
{
    public function slateSize(): int
    {
        return 15;
    }

    public function tierSpec(): ?array
    {
        return [1 => 5, 2 => 5, 3 => 5];
    }

    /**
     * Match is deliberately non-exhaustive: a tiered slate game with a null
     * or out-of-range tier cannot reach grading — publish validation
     * refuses the board — so an UnhandledMatchError here is corrupt data
     * announcing itself, not a case to paper over.
     */
    public function pointsFor(SlateGame $slateGame): int
    {
        return match ($slateGame->tier) {
            1 => 9,
            2 => 7,
            3 => 4,
        };
    }
}
