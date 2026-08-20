<?php

namespace App\Services\Contests;

use App\Models\Slate;
use App\Models\SlateGame;
use App\Support\Cadence;

/**
 * The Bear's weekly picks: one mythical house contestant, one theme, a
 * side on every game — stamped onto the slate at publish, inside
 * PublishSlate::force()'s transaction.
 *
 * The theme rotates by the FANS' week number — one Bear league-wide per
 * SATURDAY (the OG had a single creature taunting everyone), so a split
 * opening week is two cards and two Bears, and a test can still predict
 * both. Every derivation is a total function of columns publish
 * validation already requires non-null (favorite_team_id, the game's two
 * sides) — the Bear can never fail to have an opinion.
 *
 * Seeding is guarded by `bear_theme === null` at the call site: a slate
 * CLONED from a sibling room arrives with the Bear already aboard and
 * keeps him verbatim, the same identical-house-slate rule the lines
 * follow. Taglines are deliberately not stored — Voice renders them per
 * theme, in the reader's register.
 */
class BearPicks
{
    /** @var list<string> Backing values of slates.bear_theme. */
    public const THEMES = ['favorites', 'dogs', 'home', 'road', 'alternating'];

    /**
     * The theme as an INSTRUCTION — who the Bear rides, said plainly on
     * the pick surface. His personality lives in Voice
     * (picks.bear.tagline.*), never here: a reader must always be able to
     * tell his sides from his trash talk.
     */
    public static function themeLine(string $theme): string
    {
        return match ($theme) {
            'favorites' => 'The Bear rides the favorites this week.',
            'dogs' => 'The Bear rides the underdogs this week.',
            'home' => 'The Bear backs every home team this week.',
            'road' => 'The Bear backs every road team this week.',
            'alternating' => 'The Bear alternates chalk and dogs down the card.',
        };
    }

    public function seed(Slate $slate): void
    {
        $slate->loadMissing(['week', 'games.game']);

        $theme = self::THEMES[Cadence::displayWeekNumber($slate->week, $slate->saturday) % count(self::THEMES)];

        $slate->update(['bear_theme' => $theme]);

        foreach ($slate->games as $slateGame) {
            $slateGame->update(['bear_team_id' => $this->sideFor($theme, $slateGame)]);
        }
    }

    private function sideFor(string $theme, SlateGame $slateGame): int
    {
        $game = $slateGame->game;

        $other = fn (int $teamId): int => $teamId === $game->home_team_id
            ? $game->away_team_id
            : $game->home_team_id;

        return match ($theme) {
            'favorites' => $slateGame->favorite_team_id,
            'dogs' => $other($slateGame->favorite_team_id),
            'home' => $game->home_team_id,
            'road' => $game->away_team_id,
            // Chalk on the even positions, dogs on the odd — the Bear
            // hedging down the card.
            'alternating' => $slateGame->position % 2 === 0
                ? $slateGame->favorite_team_id
                : $other($slateGame->favorite_team_id),
        };
    }
}
