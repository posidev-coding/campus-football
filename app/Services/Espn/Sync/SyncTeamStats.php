<?php

namespace App\Services\Espn\Sync;

use App\Models\Athlete;
use App\Models\AthleteTeamSeason;
use App\Models\Season;
use App\Models\TeamLeader;
use App\Models\TeamSeason;
use App\Models\TeamSeasonStat;
use App\Services\Espn\EspnClient;
use App\Services\Espn\RecordParser;

/**
 * Team season statistics and statistical leaders.
 *
 * Two requests per team. Verified live: the statistics resource returns 11
 * categories (general, passing, rushing, receiving, defensive,
 * defensiveInterceptions, kicking, returning, punting, scoring, miscellaneous),
 * and the leaders resource returns 14 — passing/rushing/receiving leaders plus
 * the counting stats and QBR, sacks, tackles and interceptions.
 *
 * Leaders are stored denormalised rather than derived from athlete stats at
 * request time. The team page is a read-heavy public surface on a scale-to-zero
 * database, and one indexed read beats an aggregate every time.
 */
class SyncTeamStats
{
    public function __construct(private EspnClient $espn) {}

    public function handle(int $year, string $classification = 'FBS', int $seasonType = Season::REGULAR): int
    {
        $teamIds = TeamSeason::where('season_year', $year)
            ->when($classification !== '', fn ($q) => $q->where('classification', $classification))
            ->pluck('team_id');

        $synced = 0;

        foreach ($teamIds as $teamId) {
            $synced += $this->team((int) $teamId, $year, $seasonType);
        }

        return $synced;
    }

    public function team(int $teamId, int $year, int $seasonType = Season::REGULAR): int
    {
        return $this->statistics($teamId, $year, $seasonType)
            + $this->leaders($teamId, $year, $seasonType);
    }

    private function statistics(int $teamId, int $year, int $seasonType): int
    {
        $body = $this->espn->core(
            "seasons/{$year}/types/{$seasonType}/teams/{$teamId}/statistics",
            ttl: config('espn.cache.reference')
        );

        $categories = $body['splits']['categories'] ?? null;

        if (empty($categories)) {
            return 0;
        }

        $synced = 0;

        foreach ($categories as $category) {
            $name = $category['name'] ?? null;

            if ($name === null || empty($category['stats'])) {
                continue;
            }

            /*
             * Keyed by ESPN's stat name — never by position.
             *
             * The national rank rides along on every stat and is kept, because
             * it is the whole basis of the national team stats screen and costs
             * nothing extra: ESPN has already computed "81st in average gain"
             * for us. Discarding it, as this did originally, would mean either
             * no such screen or ranking 136 teams ourselves on every read.
             *
             * `display` and `rank` rather than a bare scalar, so a caller can
             * always ask for both without a second lookup.
             */
            $stats = [];

            foreach ($category['stats'] as $stat) {
                if (isset($stat['name'])) {
                    $stats[$stat['name']] = [
                        'display' => $stat['displayValue'] ?? $stat['value'] ?? null,
                        'value' => $stat['value'] ?? null,
                        'rank' => $stat['rank'] ?? null,
                        'label' => $stat['displayName'] ?? $stat['shortDisplayName'] ?? $stat['name'],
                    ];
                }
            }

            TeamSeasonStat::updateOrCreate(
                ['team_id' => $teamId, 'season_year' => $year, 'season_type' => $seasonType, 'category' => $name],
                ['stats' => $stats]
            );

            $synced++;
        }

        return $synced;
    }

    /**
     * Make sure a leader's athlete exists before referencing it.
     *
     * This matters more than it looks. ESPN serves only the CURRENT roster, so
     * for any past season most statistical leaders have graduated or
     * transferred and are absent from our athletes table. Skipping them dropped
     * Georgia's 2025 leaders from 14 categories to 4 — losing the passing,
     * receiving and tackling leaders the team page is built around.
     *
     * Resolving the season-scoped athlete costs one request each, but only for
     * players we do not already have: roughly ten per team on a historical
     * backfill and close to zero for the current season, and cached either way.
     * It is also the only route to a historical roster entry at all.
     */
    private function ensureAthlete(int $athleteId, int $year, int $teamId): bool
    {
        if (Athlete::whereKey($athleteId)->exists()) {
            return true;
        }

        $body = $this->espn->core("seasons/{$year}/athletes/{$athleteId}", ttl: config('espn.cache.reference'));

        if ($body === null) {
            return false;
        }

        $name = $body['fullName'] ?? $body['displayName'] ?? null;

        if ($name === null) {
            return false;
        }

        Athlete::updateOrCreate(
            ['id' => $athleteId],
            [
                'slug' => $body['slug'] ?? null,
                'first_name' => $body['firstName'] ?? null,
                'last_name' => $body['lastName'] ?? null,
                'display_name' => $name,
                'short_name' => $body['shortName'] ?? null,
                'headshot_url' => $body['headshot']['href'] ?? null,
                'height_in' => isset($body['height']) ? (int) $body['height'] : null,
                'display_height' => $body['displayHeight'] ?? null,
                'weight_lb' => isset($body['weight']) ? (int) $body['weight'] : null,
                'display_weight' => $body['displayWeight'] ?? null,
                'birth_city' => $body['birthPlace']['city'] ?? null,
                'birth_state' => $body['birthPlace']['state'] ?? null,
                'birth_country' => $body['birthPlace']['country'] ?? null,
                'is_active' => (bool) ($body['active'] ?? true),
            ]
        );

        // Records that this player was on this team that season — a roster
        // entry we could not otherwise reconstruct.
        AthleteTeamSeason::firstOrCreate(
            ['athlete_id' => $athleteId, 'season_year' => $year],
            [
                'team_id' => $teamId,
                'jersey' => $body['jersey'] ?? null,
                'position_id' => isset($body['position']['id']) ? (int) $body['position']['id'] : null,
                'experience_class' => $body['experience']['displayValue'] ?? null,
            ]
        );

        return true;
    }

    private function leaders(int $teamId, int $year, int $seasonType): int
    {
        $body = $this->espn->core(
            "seasons/{$year}/types/{$seasonType}/teams/{$teamId}/leaders",
            ttl: config('espn.cache.reference')
        );

        if (empty($body['categories'])) {
            return 0;
        }

        $synced = 0;

        foreach ($body['categories'] as $category) {
            $name = $category['name'] ?? null;

            if ($name === null || empty($category['leaders'])) {
                continue;
            }

            foreach (array_slice($category['leaders'], 0, 3) as $index => $leader) {
                $athleteId = RecordParser::athleteIdFromRef($leader['athlete']['$ref'] ?? '');

                if ($athleteId === null || ! $this->ensureAthlete($athleteId, $year, $teamId)) {
                    continue;
                }

                TeamLeader::updateOrCreate(
                    [
                        'team_id' => $teamId,
                        'season_year' => $year,
                        'season_type' => $seasonType,
                        'category' => $name,
                        'rank' => $index + 1,
                    ],
                    [
                        'athlete_id' => $athleteId,
                        'value' => isset($leader['value']) ? (float) $leader['value'] : null,
                        'display_value' => $leader['displayValue'] ?? null,
                    ]
                );

                $synced++;
            }
        }

        return $synced;
    }
}
