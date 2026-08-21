<?php

namespace App\Actions;

use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\User;
use App\Support\SlateAuthority;
use InvalidArgumentException;

/**
 * Assign or clear a draft game's tier. Null clears — an untiered mode's
 * games stay null, and the engine's publish validation is what holds a
 * tiered slate to its exact spec, not this setter.
 */
class SetSlateGameTier
{
    public function handle(User $actor, Slate $slate, SlateGame $slateGame, ?int $tier): void
    {
        SlateAuthority::commissioner($actor, $slate);
        SlateAuthority::draft($slate);
        SlateAuthority::onSlate($slate, $slateGame);

        if ($tier !== null && ($tier < 1 || $tier > 3)) {
            throw new InvalidArgumentException("Tier {$tier} does not exist.");
        }

        $slateGame->update(['tier' => $tier]);
    }
}
