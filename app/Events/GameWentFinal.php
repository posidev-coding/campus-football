<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sync pass just flipped this game to completed.
 *
 * The moment a pick'em contest can settle picks for the game — the row
 * already carries the final score when this fires, because the event is
 * dispatched after save. Scalar id only, for the same serialization reason
 * as GameScoreChanged. No listeners exist yet.
 */
class GameWentFinal
{
    use Dispatchable;

    public function __construct(public int $gameId) {}
}
