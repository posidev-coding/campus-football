<?php

namespace App\Services\Espn\Sync;

use App\Models\Athlete;
use App\Models\Injury;
use App\Models\TeamSeason;
use App\Services\Espn\EspnClient;
use App\Services\Espn\RecordParser;
use Carbon\CarbonImmutable;

/**
 * Injury reports.
 *
 * The endpoint is live but returns an empty collection out of season, which is
 * the correct answer rather than a failure — so an empty result never clears
 * what we already hold.
 *
 * Rows are replaced per team on each run, because ESPN publishes a current
 * report rather than an event stream: a player who no longer appears has
 * recovered, and leaving a stale row would keep them listed as out forever.
 * That replacement only happens when the feed actually returns something.
 */
class SyncInjuries
{
    public function __construct(private EspnClient $espn) {}

    public function handle(int $year, string $classification = 'FBS'): int
    {
        $teamIds = TeamSeason::where('season_year', $year)
            ->when($classification !== '', fn ($q) => $q->where('classification', $classification))
            ->pluck('team_id');

        $synced = 0;

        foreach ($teamIds as $teamId) {
            $synced += $this->team((int) $teamId);
        }

        return $synced;
    }

    public function team(int $teamId): int
    {
        $rows = [];

        foreach ($this->espn->paginate("teams/{$teamId}/injuries", ttl: config('espn.cache.schedule')) as $injury) {
            if ($injury === null) {
                continue;
            }

            $athleteId = RecordParser::athleteIdFromRef($injury['athlete']['$ref'] ?? '');

            if ($athleteId === null || ! Athlete::whereKey($athleteId)->exists()) {
                continue;
            }

            $rows[] = [
                'athlete_id' => $athleteId,
                'team_id' => $teamId,
                'status' => $injury['status'] ?? null,
                'type' => $injury['type']['name'] ?? $injury['type'] ?? null,
                'detail' => $injury['shortComment'] ?? $injury['longComment'] ?? null,
                'side' => $injury['side']['name'] ?? null,
                'reported_at' => isset($injury['date']) ? CarbonImmutable::parse($injury['date']) : null,
            ];
        }

        // An empty report is not a reason to wipe the last known one — that is
        // just what the offseason looks like.
        if ($rows === []) {
            return 0;
        }

        Injury::where('team_id', $teamId)->delete();

        foreach ($rows as $row) {
            Injury::create($row);
        }

        return count($rows);
    }
}
