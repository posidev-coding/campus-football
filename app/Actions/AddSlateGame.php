<?php

namespace App\Actions;

use App\Models\Game;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\User;
use App\Services\Contests\ContestLine;
use App\Support\SlateAuthority;
use InvalidArgumentException;

/**
 * Put a game on a draft board.
 *
 * Eligibility is checked at ADD time as well as at publish — this is
 * reachable from a public Livewire method, and the builder's filtered list
 * is presentation, not the gate. A game may sit here without a line (the
 * builder shows it as pending); publish is where a line becomes mandatory.
 */
class AddSlateGame
{
    public function handle(User $actor, Slate $slate, Game $game): SlateGame
    {
        SlateAuthority::commissioner($actor, $slate);
        SlateAuthority::draft($slate);

        // ONE BOARD, ONE SATURDAY — a split ESPN week satisfies the week-id
        // check twice over, so the slate's own Saturday is the honest half
        // of the eligibility question (the rule publish validation holds).
        $sameSaturday = $game->kickoff_at?->timezone(config('cfb.timezone'))->toDateString()
            === $slate->saturday?->toDateString();

        if (! $game->inSlateWindow() || $game->week_id !== $slate->week_id || ! $sameSaturday || $game->completed) {
            throw new InvalidArgumentException("Game {$game->id} is not eligible for slate {$slate->id}.");
        }

        // Seed the contest line from the book, half-pointed by the league's
        // no-push law. No market yet → the row stays honestly empty and the
        // builder offers "Use line" once the book posts one.
        $game->loadMissing('odds');

        return SlateGame::firstOrCreate(
            ['slate_id' => $slate->id, 'game_id' => $game->id],
            [
                'position' => (int) $slate->games()->max('position') + 1,
                ...(ContestLine::seedValues($game) ?? []),
            ],
        );
    }
}
