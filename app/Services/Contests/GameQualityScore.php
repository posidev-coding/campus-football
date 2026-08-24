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
 *                           money is going; absent an open there is nothing
 *                           to measure, and the part is null, not 0
 *   rankings         0–10   both sides ranked 10, one side 5 (GameRanks)
 *   conference game  0–5
 *
 * Weights are a first calibration, expected to be tuned against a real
 * season's slates. Callers iterating many games should eager-load `odds`
 * and `predictor`; loadMissing below keeps a one-off call safe.
 *
 * THAT TUNING CANNOT BE DONE FROM HISTORY. Three of the five components ride
 * ESPN feeds that are current-window only: measured 2026-08-24, 4,847 completed
 * games across 2021–2025 carry zero `matchup_quality` and zero odds of any
 * kind, while 2026 carries both. So the only way a re-fit ever gets labeled
 * rows is to WRITE THEM DOWN AS THEY HAPPEN — which is what `components()`
 * exists for, and why `PublishSlate` snapshots it onto every slate_games row.
 *
 * Hence the split:
 *
 *   components()  everything that went into the score, RAW inputs beside
 *                 weighted parts. A re-fit needs the feature, not the product.
 *   total()       the 0–100 number, summed from the weighted parts.
 *   for()         the two composed — what every live caller still asks for.
 *
 * The rule that makes the snapshot worth keeping: a part is NULL when its
 * signal is ABSENT and 0.0 only when it is present and zero. An unrated game
 * scoring 0 for matchup quality would teach a future re-fit that unrated games
 * are bad games. They are unmeasured, which is not the same thing, and it is
 * the same "never write a default when data is missing" rule the whole app
 * turns on. `total()` skips the nulls, so the live score is unchanged.
 */
class GameQualityScore
{
    /**
     * Stamped into every snapshot. Bump it in the same commit that changes
     * the shape, so a re-fit reading a season of rows can tell which
     * definition of "tightness" it is looking at.
     */
    public const SNAPSHOT_VERSION = 1;

    public static function for(Game $game): ?float
    {
        $components = self::components($game);

        return $components === null ? null : self::total($components);
    }

    /**
     * Everything the score is made of, or null when the game cannot be
     * scored at all — no usable current line, the same condition `for()`
     * has always returned null for.
     *
     * @return array{v: int, raw: array{matchup_quality: float|null, spread: float, open_spread: float|null, home_rank: int|null, away_rank: int|null, conference_game: bool}, weighted: array{matchup: float|null, tightness: float, movement: float|null, rankings: float, conference: float}}|null
     */
    public static function components(Game $game): ?array
    {
        $game->loadMissing('odds', 'predictor');

        $current = self::usableCurrentOdd($game);

        if ($current === null) {
            return null;
        }

        $matchup = $game->predictor?->matchup_quality;

        $open = $game->odds->first(fn (GameOdd $odd) => $odd->phase === GameOdd::OPEN
            && $odd->provider_id === $current->provider_id
            && $odd->spread !== null);

        $ranks = GameRanks::forGame($game);
        $rankedSides = ($ranks['home'] !== null ? 1 : 0) + ($ranks['away'] !== null ? 1 : 0);

        return [
            'v' => self::SNAPSHOT_VERSION,
            /*
             * The features, unweighted. A regression re-fit needs these and
             * not the products — the weights are exactly what it is solving
             * for, and a stored product has them baked in irreversibly.
             */
            'raw' => [
                'matchup_quality' => $matchup,
                'spread' => (float) $current->spread,
                'open_spread' => $open?->spread === null ? null : (float) $open->spread,
                'home_rank' => $ranks['home'],
                'away_rank' => $ranks['away'],
                'conference_game' => (bool) $game->conference_game,
            ],
            /*
             * Deliberately NOT rounded. The parts are summed and rounded once
             * in total(), which is what `for()` has always returned; rounding
             * here first would let the snapshot and the live score disagree in
             * the second decimal.
             */
            'weighted' => [
                'matchup' => $matchup === null ? null : $matchup * 0.6,
                'tightness' => max(0.0, 14.0 - abs($current->spread)) / 14.0 * 20.0,
                // Absent an open from the same book there is no movement to
                // measure — which is not the same as a line that did not move.
                'movement' => $open === null ? null : min(abs($current->spread - $open->spread), 7.0) / 7.0 * 5.0,
                // Zero, not null: GameRanks answers "unranked" definitively,
                // so neither side ranked is a measurement.
                'rankings' => $rankedSides * 5.0,
                'conference' => $game->conference_game ? 5.0 : 0.0,
            ],
        ];
    }

    /**
     * The 0–100 score, summed from the parts a game actually has.
     *
     * Nulls are SKIPPED, not counted as zero — a missing signal contributes
     * nothing, which is what makes every component an additive bonus rather
     * than a defaulted middle value.
     *
     * @param  array{weighted: array<string, float|null>}  $components
     */
    public static function total(array $components): float
    {
        return round(array_sum(array_filter(
            $components['weighted'],
            fn (?float $part): bool => $part !== null,
        )), 2);
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
