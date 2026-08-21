<?php

namespace App\Services\Contests;

use App\Models\Game;
use App\Models\GameOdd;
use App\Support\GameRanks;

/**
 * How much a game deserves a slate slot, 0–100 — the commissioner's
 * SUGGESTION signal, never the author of a slate.
 *
 * Pure over rows we already hold (predictor, odds, rankings): zero ESPN
 * requests, the cfb:aggregate discipline. A game with no usable current
 * line returns NULL and is excluded from suggestion entirely — an ATS
 * contest cannot slate it, and null means "cannot be scored", never "scored
 * 0.0".
 *
 * Components are additive BONUSES so a missing signal contributes nothing
 * rather than a defaulted middle value:
 *
 *   matchup quality  0–60   ESPN's forward-looking model (never the
 *                           retrospective game_quality, which does not
 *                           exist at slate-build time)
 *   spread tightness 0–20   closer lines make better contests
 *   line movement    0–5    |current − open| — the public proxy for where
 *                           money is going; absent an open, contributes 0
 *   rankings         0–10   both sides ranked 10, one side 5 (GameRanks)
 *   conference game  0–5
 *
 * Weights are a first calibration, expected to be tuned against a real
 * season's slates. Callers iterating many games should eager-load `odds`
 * and `predictor`; loadMissing below keeps a one-off call safe.
 */
class GameQualityScore
{
    public static function for(Game $game): ?float
    {
        $game->loadMissing('odds', 'predictor');

        $current = self::usableCurrentOdd($game);

        if ($current === null) {
            return null;
        }

        $score = 0.0;

        if ($game->predictor?->matchup_quality !== null) {
            $score += $game->predictor->matchup_quality * 0.6;
        }

        $score += max(0.0, 14.0 - abs($current->spread)) / 14.0 * 20.0;

        $open = $game->odds->first(fn (GameOdd $odd) => $odd->phase === GameOdd::OPEN
            && $odd->provider_id === $current->provider_id
            && $odd->spread !== null);

        if ($open !== null) {
            $score += min(abs($current->spread - $open->spread), 7.0) / 7.0 * 5.0;
        }

        $ranks = GameRanks::forGame($game);
        $rankedSides = ($ranks['home'] !== null ? 1 : 0) + ($ranks['away'] !== null ? 1 : 0);
        $score += $rankedSides * 5.0;

        if ($game->conference_game) {
            $score += 5.0;
        }

        return round($score, 2);
    }

    /**
     * The line a slate would freeze: a current-phase row carrying both a
     * spread and a favorite. Newest capture wins when providers disagree.
     */
    public static function usableCurrentOdd(Game $game): ?GameOdd
    {
        return $game->odds
            ->filter(fn (GameOdd $odd) => $odd->phase === GameOdd::CURRENT
                && $odd->spread !== null
                && $odd->favorite_team_id !== null)
            ->sortByDesc('captured_at')
            ->first();
    }
}
