<?php

namespace App\Services\Espn\Sync;

use App\Models\Athlete;
use App\Models\NationalLeader;
use App\Models\Season;
use App\Models\Team;
use App\Services\Espn\EspnClient;
use App\Services\Espn\RecordParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * National statistical leaders.
 *
 * The cheapest feed in the application by a distance: ONE request returns 13
 * categories of 100 athletes each — 1,300 leaderboard rows for a single call.
 * Compare that with the ~1,200 requests a full per-team athlete stats pass
 * costs, and this is what makes a national leaders page essentially free.
 *
 * It has to be the core host. The site equivalent
 * (`site/.../leaders`) returns 404 outright, the same way the site rankings
 * endpoint silently refuses to serve the CFP poll — a reminder that "the other
 * host probably has it too" is never a safe assumption with this API.
 *
 * Two properties of the payload shape the code:
 *
 *   - Entries carry `$ref` URLs only, no names. Athlete and team ids come out
 *     of the URL, which is what RecordParser already does elsewhere.
 *   - The feed spans EVERY division — 245 distinct teams for 2025, against 136
 *     in FBS. Nothing here filters; scoping is the reader's job, through
 *     team_seasons.classification.
 */
class SyncNationalLeaders
{
    private const LIMIT = 100;

    public function __construct(private EspnClient $espn) {}

    /**
     * Every published season type for a year that actually has one.
     */
    public function season(int $year): int
    {
        $synced = 0;

        foreach ([Season::REGULAR, Season::POSTSEASON] as $type) {
            $synced += $this->handle($year, $type);
        }

        return $synced;
    }

    public function handle(int $year, int $seasonType = Season::REGULAR): int
    {
        $body = $this->espn->core(
            "seasons/{$year}/types/{$seasonType}/leaders",
            ['limit' => self::LIMIT],
            ttl: config('espn.cache.schedule')
        );

        if ($body === null || empty($body['categories'])) {
            return 0;
        }

        $knownTeams = Team::pluck('id')->flip();
        $synced = 0;

        foreach ($body['categories'] as $category) {
            $synced += $this->storeCategory($category, $year, $seasonType, $knownTeams);
        }

        return $synced;
    }

    /**
     * @param  Collection<int, int>  $knownTeams
     */
    private function storeCategory(array $category, int $year, int $seasonType, $knownTeams): int
    {
        $name = $category['name'] ?? null;
        $leaders = $category['leaders'] ?? [];

        // Some categories come back present but empty — kickoffYards had zero
        // entries for 2025. An empty category is not an error and must not
        // clear what is already stored.
        if ($name === null || $leaders === []) {
            return 0;
        }

        $rows = [];

        foreach ($leaders as $index => $leader) {
            $athleteId = RecordParser::athleteIdFromRef(data_get($leader, 'athlete.$ref', ''));

            if ($athleteId === null) {
                continue;
            }

            $teamId = RecordParser::teamIdFromRef(data_get($leader, 'team.$ref', ''));

            $rows[] = [
                'athlete_id' => $athleteId,
                // Null rather than skip when we do not carry the team: the
                // ranking is still true, and dropping it would leave gaps in
                // the middle of a top-100 list.
                'team_id' => $teamId !== null && $knownTeams->has($teamId) ? $teamId : null,
                'rank' => $index + 1,
                'value' => $leader['value'] ?? null,
                'display_value' => $leader['displayValue'] ?? null,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        DB::transaction(function () use ($rows, $year, $seasonType, $name) {
            foreach ($rows as $row) {
                NationalLeader::updateOrCreate(
                    [
                        'season_year' => $year,
                        'season_type' => $seasonType,
                        'category' => $name,
                        'rank' => $row['rank'],
                    ],
                    $row
                );
            }
        });

        return count($rows);
    }

    /**
     * Fill in athletes the leaderboard names but we have no record of.
     *
     * Unavoidable for history: rosters publish the CURRENT season only, so a
     * 2021 leader has no roster row to have come from. Roughly 500 are missing
     * on a first run and very few after, since a leaderboard is stable
     * week to week.
     *
     * Deliberately separate from `handle()` and capped, so one expensive
     * resolve pass can never turn the cheap leaders sync into a slow one.
     */
    public function resolveAthletes(int $limit = 250): int
    {
        $missing = NationalLeader::query()
            ->whereNotIn('athlete_id', Athlete::select('id'))
            ->distinct()
            ->limit($limit)
            ->pluck('athlete_id');

        $resolved = 0;

        foreach ($missing as $athleteId) {
            $body = $this->espn->core("athletes/{$athleteId}", ttl: config('espn.cache.reference'));

            if ($body === null || ! isset($body['displayName'])) {
                continue;
            }

            Athlete::firstOrCreate(
                ['id' => (int) $athleteId],
                [
                    'first_name' => $body['firstName'] ?? null,
                    'last_name' => $body['lastName'] ?? null,
                    'display_name' => $body['displayName'],
                    'short_name' => $body['shortName'] ?? null,
                    'headshot_url' => data_get($body, 'headshot.href'),
                    'display_height' => $body['displayHeight'] ?? null,
                    'display_weight' => $body['displayWeight'] ?? null,
                ]
            );

            $resolved++;
        }

        return $resolved;
    }
}
