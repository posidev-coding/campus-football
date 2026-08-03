<?php

namespace App\Services\Espn\Sync;

use App\Models\Conference;
use App\Models\ConferenceSeason;
use App\Services\Espn\EspnClient;

/**
 * Walks ESPN's season-scoped group tree into `conferences` + `conference_seasons`.
 *
 * The tree is re-parented every season, and classification is a property of
 * where a group sits in *that year's* tree — not of the conference itself.
 * Verified for 2025: 99 (NCAA) -> 90 (Division I) -> 80 (FBS) + 81 (FCS),
 * with 35 (Division II/III) hanging off 99 separately, and 11 conferences
 * under FBS.
 *
 * Because classification is inherited from the ancestor we descended through,
 * the walk carries it down rather than trying to read it off each node.
 */
class SyncConferences
{
    public function __construct(private EspnClient $espn) {}

    public function handle(int $year): int
    {
        $synced = 0;

        foreach (config('espn.classifications') as $rootGroupId => $classification) {
            $synced += $this->walk($year, (int) $rootGroupId, $classification, null);
        }

        return $synced;
    }

    /**
     * Depth-first from a classification root, recording every group it contains.
     */
    private function walk(int $year, int $groupId, string $classification, ?int $parentId): int
    {
        $group = $this->espn->core("seasons/{$year}/types/2/groups/{$groupId}");

        if ($group === null || ! isset($group['id'])) {
            return 0;
        }

        $this->store($group, $year, $classification, $parentId);

        $synced = 1;

        foreach ($this->children($year, $groupId) as $childId) {
            $synced += $this->walk($year, $childId, $classification, $groupId);
        }

        return $synced;
    }

    /**
     * @return list<int>
     */
    private function children(int $year, int $groupId): array
    {
        $body = $this->espn->core("seasons/{$year}/types/2/groups/{$groupId}/children", ['limit' => 100]);

        if ($body === null || empty($body['items'])) {
            return [];
        }

        return collect($body['items'])
            ->map(fn (array $item) => $this->groupIdFromRef($item['$ref'] ?? ''))
            ->filter()
            ->values()
            ->all();
    }

    private function store(array $group, int $year, string $classification, ?int $parentId): void
    {
        $id = (int) $group['id'];

        Conference::updateOrCreate(
            ['id' => $id],
            [
                'name' => $group['name'] ?? "Group {$id}",
                'short_name' => $group['shortName'] ?? null,
                'abbreviation' => $group['abbreviation'] ?? null,
                'logo' => $group['logos'][0]['href'] ?? null,
                'is_conference' => (bool) ($group['isConference'] ?? false),
            ]
        );

        ConferenceSeason::updateOrCreate(
            ['conference_id' => $id, 'season_year' => $year],
            ['parent_group_id' => $parentId, 'classification' => $classification]
        );
    }

    /**
     * Group ids are only reliably available in the `$ref` path — the collection
     * payload does not always inline them.
     */
    public static function groupIdFromRef(string $ref): ?int
    {
        return preg_match('#/groups/(\d+)#', $ref, $m) ? (int) $m[1] : null;
    }
}
