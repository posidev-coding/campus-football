<?php

namespace App\Services\Espn\Sync;

use App\Models\Game;
use App\Models\GamePredictor;
use App\Services\Espn\EspnClient;
use Carbon\CarbonImmutable;

/**
 * ESPN's own quality metrics — inputs to the Game Quality Score that suggests
 * contest tiers.
 *
 * IMPORTANT: `gameQuality` and `matchupQuality` are not interchangeable, and
 * only one of them is usable for tiering.
 *
 * Verified against live data: a completed 2025 game returns both
 * (gameQuality 70.8, matchupQuality 52.1), but upcoming games return
 * matchupQuality alone. `gameQuality` is retrospective — it scores how good the
 * game turned out — so it does not exist at the moment a commissioner is
 * building a slate, which is the only moment tiering cares about.
 *
 * So the Game Quality Score must lean on `matchupQuality` plus the other
 * forward-looking signals (line movement, spread tightness, rankings,
 * conference weight, group affinity). `gameQuality` is stored when present but
 * is only ever useful after the fact.
 *
 * Unlike odds, none of this is carried on the scoreboard. The predictor is a
 * per-game core-API resource costing one request per game, so a whole season
 * would be ~950 requests for data that only matters while a slate is being
 * built. This is deliberately scoped to upcoming games — 60-80 a week, once or
 * twice a week. Historical predictors are never fetched.
 */
class SyncPredictors
{
    public function __construct(private EspnClient $espn) {}

    /**
     * Sync predictors for games kicking off in the next `$days` days.
     */
    public function upcoming(int $days = 10, bool $saturdayOnly = true): int
    {
        $now = CarbonImmutable::now();

        $games = Game::query()
            ->where('completed', false)
            ->whereBetween('kickoff_at', [$now, $now->addDays($days)])
            ->when($saturdayOnly, fn ($q) => $q->slateEligible())
            ->orderBy('kickoff_at')
            ->pluck('id');

        $synced = 0;

        foreach ($games as $gameId) {
            if ($this->game((int) $gameId)) {
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * One game's predictor. Returns false when ESPN has not modelled it yet,
     * which is ordinary for a game far out or involving an FCS opponent.
     */
    public function game(int $gameId): bool
    {
        $body = $this->espn->core(
            "events/{$gameId}/competitions/{$gameId}/predictor",
            ttl: config('espn.cache.schedule')
        );

        if ($body === null) {
            return false;
        }

        $home = $this->statistics($body['homeTeam'] ?? []);
        $away = $this->statistics($body['awayTeam'] ?? []);

        // Both are published on either side with the same value. gameQuality is
        // routinely absent on an unplayed game — see the class docblock — so
        // matchupQuality is what makes a row worth writing.
        $gameQuality = $home['gameQuality'] ?? $away['gameQuality'] ?? null;
        $matchupQuality = $home['matchupQuality'] ?? $away['matchupQuality'] ?? null;

        if ($matchupQuality === null && $gameQuality === null) {
            return false;
        }

        GamePredictor::updateOrCreate(
            ['game_id' => $gameId],
            array_filter([
                'game_quality' => $gameQuality,
                'matchup_quality' => $matchupQuality,
                'home_projection' => $home['gameProjection'] ?? null,
                'away_projection' => $away['gameProjection'] ?? null,
                'home_opp_strength' => $home['oppSeasonStrengthRating'] ?? null,
                'away_opp_strength' => $away['oppSeasonStrengthRating'] ?? null,
                'synced_at' => CarbonImmutable::now(),
            ], fn ($value) => $value !== null)
        );

        return true;
    }

    /**
     * Flatten ESPN's name/value statistics list into a lookup.
     *
     * Addressed by name, never by position — the same discipline the standings
     * parser uses, and for the same reason.
     *
     * @return array<string, float>
     */
    private function statistics(array $side): array
    {
        $stats = [];

        foreach ($side['statistics'] ?? [] as $stat) {
            if (isset($stat['name'])) {
                $stats[$stat['name']] = (float) ($stat['value'] ?? $stat['displayValue'] ?? 0);
            }
        }

        return $stats;
    }
}
