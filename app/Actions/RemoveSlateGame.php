<?php

namespace App\Actions;

use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\User;
use App\Support\SlateAuthority;

/**
 * Take a game off a draft slate. Positions are left sparse — they only
 * drive an ORDER BY, and reindexing on every removal buys nothing the sort
 * doesn't already have. The tiebreaker FK clears itself (nullOnDelete), so
 * removing the designated game simply re-opens that publish requirement.
 */
class RemoveSlateGame
{
    public function handle(User $actor, Slate $slate, SlateGame $slateGame): void
    {
        SlateAuthority::commissioner($actor, $slate);
        SlateAuthority::draft($slate);
        SlateAuthority::onSlate($slate, $slateGame);

        $slateGame->delete();
    }
}
