<?php

namespace App\Services\Espn\Sync;

use App\Models\Athlete;
use App\Models\AthleteTeamSeason;
use App\Models\Coach;
use App\Models\CoachTeamSeason;
use App\Models\Position;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Services\Espn\EspnClient;
use Illuminate\Support\Facades\Log;

/**
 * Rosters — the bulk of the player layer, and cheaper than it looks.
 *
 * One request per team returns the whole roster with everything a player page
 * needs already inlined: headshot URL, height, weight, jersey, position, class,
 * hometown and injury status. Georgia's returns 180 athletes. Across 136 FBS
 * teams that is 136 requests for roughly 16,000 players — a fraction of what
 * fetching each athlete individually would cost.
 *
 * Rosters change slowly, so this runs weekly. Nothing here is on a live path.
 *
 * IMPORTANT: only the CURRENT roster is available. Passing `?season=2025`
 * echoes that year back but returns zero athletes — verified live — so
 * historical rosters cannot be backfilled from here. The season is therefore
 * read out of the payload rather than taken from the caller, so a roster is
 * never filed under a year it does not belong to.
 */
class SyncRosters
{
    public function __construct(private EspnClient $espn) {}

    /**
     * Sync every team in a classification for a season.
     */
    public function handle(int $year, string $classification = 'FBS'): int
    {
        $teamIds = TeamSeason::where('season_year', $year)
            ->when($classification !== '', fn ($q) => $q->where('classification', $classification))
            ->pluck('team_id');

        $synced = 0;

        foreach ($teamIds as $teamId) {
            $synced += $this->team((int) $teamId, $year);
        }

        return $synced;
    }

    /**
     * One team's roster. Returns the number of athletes written.
     */
    public function team(int $teamId, int $requestedYear): int
    {
        $body = $this->espn->site("teams/{$teamId}/roster", ttl: config('espn.cache.reference'));

        if ($body === null || empty($body['athletes'])) {
            return 0;
        }

        // ESPN decides which season this roster is, not us. Trusting the
        // requested year would file the current roster under whatever year the
        // caller happened to pass.
        $year = (int) ($body['season']['year'] ?? $requestedYear);

        if ($year !== $requestedYear) {
            Log::info('Roster returned a different season than requested', [
                'team_id' => $teamId,
                'requested' => $requestedYear,
                'returned' => $year,
            ]);
        }

        $this->storeCoaches($body['coach'] ?? [], $teamId, $year);

        $synced = 0;

        foreach ($body['athletes'] as $group) {
            $positionGroup = $group['position'] ?? null;

            foreach ($group['items'] ?? [] as $item) {
                if ($this->storeAthlete($item, $teamId, $year, $positionGroup)) {
                    $synced++;
                }
            }
        }

        return $synced;
    }

    private function storeAthlete(array $item, int $teamId, int $year, ?string $positionGroup): bool
    {
        $id = isset($item['id']) ? (int) $item['id'] : null;

        if ($id === null) {
            return false;
        }

        Athlete::updateOrCreate(
            ['id' => $id],
            [
                'slug' => $item['slug'] ?? null,
                'first_name' => $item['firstName'] ?? null,
                'last_name' => $item['lastName'] ?? null,
                'display_name' => $item['fullName'] ?? $item['displayName'] ?? "Athlete {$id}",
                'short_name' => $item['shortName'] ?? null,
                'headshot_url' => $item['headshot']['href'] ?? null,
                'height_in' => isset($item['height']) ? (int) $item['height'] : null,
                'display_height' => $item['displayHeight'] ?? null,
                'weight_lb' => isset($item['weight']) ? (int) $item['weight'] : null,
                'display_weight' => $item['displayWeight'] ?? null,
                'birth_city' => $item['birthPlace']['city'] ?? null,
                'birth_state' => $item['birthPlace']['state'] ?? null,
                'birth_country' => $item['birthPlace']['country'] ?? null,
                'is_active' => ($item['status']['type'] ?? 'active') === 'active',
            ]
        );

        AthleteTeamSeason::updateOrCreate(
            ['athlete_id' => $id, 'season_year' => $year],
            [
                'team_id' => $teamId,
                'jersey' => $item['jersey'] ?? null,
                'position_id' => $this->position($item['position'] ?? null),
                'position_group' => $this->normalizeGroup($positionGroup),
                'experience_class' => $item['experience']['displayValue'] ?? null,
                'status' => $item['status']['name'] ?? null,
            ]
        );

        return true;
    }

    /**
     * ESPN's group keys are camelCase and inconsistent in case; normalise to
     * snake_case so the team page can group on a stable value.
     */
    private function normalizeGroup(?string $group): ?string
    {
        return match ($group) {
            'offense' => 'offense',
            'defense' => 'defense',
            'specialTeam' => 'special_teams',
            'injuredReserveOrOut' => 'injured_reserve',
            'suspended' => 'suspended',
            'practiceSquad' => 'practice_squad',
            default => $group,
        };
    }

    private function position(?array $position): ?int
    {
        if ($position === null || ! isset($position['id'])) {
            return null;
        }

        $id = (int) $position['id'];

        Position::updateOrCreate(
            ['id' => $id],
            [
                'name' => $position['displayName'] ?? $position['name'] ?? "Position {$id}",
                'abbreviation' => $position['abbreviation'] ?? null,
                'slug' => $position['slug'] ?? null,
                // ESPN nests the parent position but does not always include
                // its id, so only record it when it is actually there.
                'parent_id' => isset($position['parent']['id']) ? (int) $position['parent']['id'] : null,
            ]
        );

        return $id;
    }

    private function storeCoaches(array $coaches, int $teamId, int $year): void
    {
        foreach ($coaches as $coach) {
            if (! isset($coach['id'])) {
                continue;
            }

            $id = (int) $coach['id'];
            $name = trim(($coach['firstName'] ?? '').' '.($coach['lastName'] ?? ''));

            Coach::updateOrCreate(
                ['id' => $id],
                [
                    'first_name' => $coach['firstName'] ?? null,
                    'last_name' => $coach['lastName'] ?? null,
                    'display_name' => $name !== '' ? $name : "Coach {$id}",
                ]
            );

            CoachTeamSeason::updateOrCreate(
                ['coach_id' => $id, 'team_id' => $teamId, 'season_year' => $year],
                ['experience' => $coach['experience'] ?? null]
            );
        }
    }
}
