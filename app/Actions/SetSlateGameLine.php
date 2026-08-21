<?php

namespace App\Actions;

use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\User;
use App\Services\Contests\ContestLine;
use App\Services\Contests\GameQualityScore;
use App\Support\SlateAuthority;
use InvalidArgumentException;

/**
 * The commissioner sets a draft game's contest line.
 *
 * `$burden` is the favorite's number as a positive half-point (7.5 means
 * "the favorite by 7½"); direction stays with the favorite the book named,
 * so an adjustment can shorten or stretch the number but never flip who
 * carries it. The law and the leash both live in ContestLine: always a
 * half point, never more than MAX_ADJUSTMENT off the book's CURRENT number
 * — checked against the live market at set time, and the market reference
 * on the row is refreshed to match, so the audit trail always says what
 * the book read when the commissioner made the call.
 *
 * A row the book had nothing for at add time seeds through here too: the
 * first legal set fills spread, favorite and provenance in one move.
 */
class SetSlateGameLine
{
    public function handle(User $actor, Slate $slate, SlateGame $slateGame, float $burden): void
    {
        SlateAuthority::commissioner($actor, $slate);
        SlateAuthority::draft($slate);
        SlateAuthority::onSlate($slate, $slateGame);

        if (! ContestLine::isHalfPoint($burden)) {
            throw new InvalidArgumentException("A contest line of {$burden} is not a half point; the league does not push.");
        }

        $slateGame->loadMissing('game.odds');
        $current = GameQualityScore::usableCurrentOdd($slateGame->game);

        if ($current === null) {
            throw new InvalidArgumentException("Game {$slateGame->game_id} has no market line to set against.");
        }

        if (! ContestLine::withinBand($burden, (float) $current->spread)) {
            throw new InvalidArgumentException(
                "A burden of {$burden} is more than ".ContestLine::MAX_ADJUSTMENT." off the market's number."
            );
        }

        $slateGame->update([
            'spread' => ContestLine::signed($burden, $current->favorite_team_id, $slateGame->game),
            'market_spread' => ContestLine::signed(abs((float) $current->spread), $current->favorite_team_id, $slateGame->game),
            'favorite_team_id' => $current->favorite_team_id,
            'odds_provider' => $current->provider,
            'odds_captured_at' => $current->captured_at,
        ]);
    }
}
