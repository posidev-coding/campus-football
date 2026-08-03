<?php

namespace App\Services\Espn\Sync;

use App\Models\ConferenceSeason;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Services\Espn\EspnClient;

/**
 * Teams, and — the important part — their conference *for a given season*.
 *
 * ESPN exposes a season-scoped team resource whose `groups.$ref` names the
 * conference that team played in that year. That single field is what makes
 * historical standings reconstructable, and reading it is the entire fix for
 * the bug that has dogged this app across three versions.
 *
 * Verified against the API: Oregon resolves to group 54 (Pac-12) for 2021 and
 * group 5 (Big Ten) for 2025. A schema with one conference per team cannot
 * represent both, which is why `teams` has no conference column at all.
 */
class SyncTeams
{
    public function __construct(private EspnClient $espn) {}

    public function handle(int $year): int
    {
        $synced = 0;

        // Classification is known from the conference tree, which is synced
        // first — so resolve it by lookup rather than re-walking the tree once
        // per team.
        $classifications = ConferenceSeason::where('season_year', $year)
            ->pluck('classification', 'conference_id');

        foreach ($this->espn->paginate("seasons/{$year}/teams") as $team) {
            if ($team === null || ! isset($team['id'])) {
                continue;
            }

            $this->store($team, $year, $classifications);
            $synced++;
        }

        return $synced;
    }

    private function store(array $payload, int $year, $classifications): void
    {
        $id = (int) $payload['id'];

        Team::updateOrCreate(
            ['id' => $id],
            [
                'slug' => $payload['slug'] ?? "team-{$id}",
                'location' => $payload['location'] ?? null,
                'name' => $payload['name'] ?? null,
                'nickname' => $payload['nickname'] ?? null,
                'abbreviation' => $payload['abbreviation'] ?? null,
                'display_name' => $payload['displayName'] ?? ($payload['location'] ?? "Team {$id}"),
                'short_display_name' => $payload['shortDisplayName'] ?? null,
                'color' => $payload['color'] ?? null,
                'alt_color' => $payload['alternateColor'] ?? null,
                'logo' => $payload['logos'][0]['href'] ?? null,
                // ESPN publishes a dark-mode logo variant as the second entry.
                // The app is dark-mode-first, so this is not optional polish.
                'logo_dark' => $payload['logos'][1]['href'] ?? null,
            ]
        );

        $conferenceId = SyncConferences::groupIdFromRef($payload['groups']['$ref'] ?? '');

        TeamSeason::updateOrCreate(
            ['team_id' => $id, 'season_year' => $year],
            [
                'conference_id' => $conferenceId,
                'classification' => $conferenceId ? ($classifications[$conferenceId] ?? null) : null,
            ]
        );
    }
}
