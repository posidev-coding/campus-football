<?php

namespace App\Services\Espn\Sync;

use App\Models\Athlete;
use App\Models\Position;
use App\Models\Recruit;
use App\Models\Team;
use App\Services\Espn\EspnClient;

/**
 * High school recruiting classes.
 *
 * The path is worth recording because it 404s on every obvious guess and only
 * resolves at `.../leagues/college-football/recruiting/{year}/athletes` — 5,193
 * prospects for the 2026 class.
 *
 * Cost is the constraint. The collection returns `$ref`s, so every prospect is
 * an additional request; a full class is ~5,200. That is a lot for a long tail
 * nobody browses, so this is capped by default. The list comes back in national
 * rank order, so a cap takes the top of the class rather than an arbitrary
 * slice — which is what a recruiting page is actually for.
 */
class SyncRecruiting
{
    /** Prospects to ingest per class unless told otherwise. */
    public const DEFAULT_LIMIT = 1000;

    public function __construct(private EspnClient $espn) {}

    public function handle(int $class, int $limit = self::DEFAULT_LIMIT): int
    {
        $synced = 0;

        foreach ($this->espn->paginate("recruiting/{$class}/athletes") as $recruit) {
            if ($recruit === null) {
                continue;
            }

            if ($this->store($recruit, $class)) {
                $synced++;
            }

            if ($limit > 0 && $synced >= $limit) {
                break;
            }
        }

        return $synced;
    }

    private function store(array $payload, int $class): bool
    {
        $athlete = $payload['athlete'] ?? null;

        // A prospect with no name is not worth a row.
        if ($athlete === null || ! isset($athlete['id'])) {
            return false;
        }

        $name = $athlete['fullName'] ?? $athlete['displayName'] ?? null;

        if ($name === null) {
            return false;
        }

        $attributes = $this->attributes($payload['attributes'] ?? []);

        Recruit::updateOrCreate(
            ['espn_id' => (int) $athlete['id'], 'recruiting_class' => $class],
            [
                // `alternateId` is the athlete id they carry once they reach a
                // college roster, which is what links a prospect to a player.
                'athlete_id' => $this->linkedAthleteId($athlete),
                'display_name' => $name,
                'grade' => isset($payload['grade']) ? (int) $payload['grade'] : null,
                'national_rank' => $attributes['rank'] ?? null,
                'position_rank' => $attributes['positionRank'] ?? null,
                'state_rank' => $attributes['stateRank'] ?? null,
                'status' => $payload['status']['description'] ?? null,
                'committed_team_id' => $this->committedTeamId($payload),
                'high_school' => $athlete['highSchool']['properName'] ?? $athlete['highSchool']['name'] ?? null,
                'hometown_city' => $athlete['hometown']['city'] ?? null,
                'hometown_state' => $athlete['hometown']['stateAbbreviation'] ?? $athlete['hometown']['state'] ?? null,
                'position_id' => $this->position($athlete['position'] ?? null),
                'height_in' => isset($athlete['height']) ? (int) $athlete['height'] : null,
                'weight_lb' => isset($athlete['weight']) ? (int) $athlete['weight'] : null,
            ]
        );

        return true;
    }

    /**
     * @return array<string, int>
     */
    private function attributes(array $attributes): array
    {
        $values = [];

        foreach ($attributes as $attribute) {
            if (isset($attribute['name'], $attribute['value'])) {
                $values[$attribute['name']] = (int) $attribute['value'];
            }
        }

        return $values;
    }

    private function linkedAthleteId(array $athlete): ?int
    {
        $id = $athlete['alternateId'] ?? null;

        if ($id === null) {
            return null;
        }

        // Only link when we actually have that player, so the FK holds.
        return Athlete::whereKey((int) $id)->exists() ? (int) $id : null;
    }

    /**
     * The school a prospect signed with, taken from the schools list rather
     * than assumed to be the first entry — that list carries every visit, not
     * just the commitment.
     */
    private function committedTeamId(array $payload): ?int
    {
        $signedStatus = $payload['status']['id'] ?? null;

        foreach ($payload['schools'] ?? [] as $school) {
            if (($school['status']['id'] ?? null) !== $signedStatus) {
                continue;
            }

            if (! preg_match('#/teams/(\d+)#', $school['team']['$ref'] ?? '', $m)) {
                continue;
            }

            $teamId = (int) $m[1];

            // Only return a team we hold, so the foreign key holds.
            if (Team::whereKey($teamId)->exists()) {
                return $teamId;
            }
        }

        return null;
    }

    private function position(?array $position): ?int
    {
        if ($position === null || ! isset($position['id'])) {
            return null;
        }

        $id = (int) $position['id'];

        Position::firstOrCreate(
            ['id' => $id],
            [
                'name' => $position['displayName'] ?? $position['abbreviation'] ?? "Position {$id}",
                'abbreviation' => $position['abbreviation'] ?? null,
            ]
        );

        return $id;
    }
}
