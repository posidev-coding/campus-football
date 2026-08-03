<?php

namespace App\Services\Espn\Sync;

use App\Models\Conference;
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
    /** @var list<int> Conference ids already confirmed to exist this run. */
    private array $knownConferenceIds = [];

    public function __construct(private EspnClient $espn) {}

    public function handle(int $year): int
    {
        $synced = 0;

        // Classification is known from the conference tree, which is synced
        // first — so resolve it by lookup rather than re-walking the tree once
        // per team.
        $classifications = ConferenceSeason::where('season_year', $year)
            ->pluck('classification', 'conference_id');

        $this->knownConferenceIds = $classifications->keys()->map(intval(...))->all();

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
                'slug' => $this->uniqueSlug($payload['slug'] ?? "team-{$id}", $id),
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

        $groupId = SyncConferences::groupIdFromRef($payload['groups']['$ref'] ?? '');

        if ($groupId !== null) {
            $groupId = $this->ensureConferenceExists($groupId, $year);
        }

        [$conferenceId, $divisionId] = $this->resolveConferenceAndDivision($groupId, $year);

        TeamSeason::updateOrCreate(
            ['team_id' => $id, 'season_year' => $year],
            [
                'conference_id' => $conferenceId,
                'division_id' => $divisionId,
                'classification' => $conferenceId ? ($classifications[$conferenceId] ?? null) : null,
            ]
        );
    }

    /**
     * A team's group may be a division rather than a conference.
     *
     * Verified live: Oregon's 2021 group is 54 "Pac 12 - North", which carries
     * `isConference: false` and a parent of 9 "Pac-12 Conference". By 2025 the
     * divisions are gone and the group is the conference itself.
     *
     * Storing the division as the conference would split a 2021 conference's
     * standings into two halves with nothing to roll them up — and would make
     * ordinary division restructuring look identical to a team actually
     * changing conference.
     *
     * @return array{0: int|null, 1: int|null} [conference_id, division_id]
     */
    private function resolveConferenceAndDivision(?int $groupId, int $year): array
    {
        if ($groupId === null) {
            return [null, null];
        }

        $group = Conference::find($groupId);

        if ($group === null || $group->is_conference) {
            return [$groupId, null];
        }

        $parentId = ConferenceSeason::where('conference_id', $groupId)
            ->where('season_year', $year)
            ->value('parent_group_id');

        // A non-conference group with no conference parent (a classification
        // root, or something outside the NCAA tree) is the best answer we have.
        if ($parentId === null || ! Conference::whereKey($parentId)->value('is_conference')) {
            return [$groupId, null];
        }

        return [(int) $parentId, $groupId];
    }

    /**
     * ESPN slugs are not unique across team ids.
     *
     * Observed live: ids 2838 and another record both claim
     * "tiffin-university-dragons". Slug is this app's route key, so it has to
     * stay unique — the id is appended on collision rather than dropping the
     * team or letting one silently overwrite the other's URL.
     */
    private function uniqueSlug(string $slug, int $teamId): string
    {
        $takenByAnother = Team::where('slug', $slug)
            ->where('id', '!=', $teamId)
            ->exists();

        return $takenByAnother ? "{$slug}-{$teamId}" : $slug;
    }

    /**
     * Teams can point at groups outside the NCAA tree.
     *
     * ESPN's season team list carries all 807 schools it tracks, including NAIA
     * and other non-NCAA programs whose groups hang off no classification root —
     * so walking down from FBS/FCS/DII-III legitimately misses them. Rather than
     * drop those teams or leave a dangling reference, resolve the group on
     * demand. Classification stays null, which is what marks them as outside
     * the divisions this app ranks.
     *
     * Returns null if the group cannot be resolved at all, so the team is still
     * stored with no conference rather than failing the whole sync.
     */
    private function ensureConferenceExists(int $conferenceId, int $year): ?int
    {
        if (in_array($conferenceId, $this->knownConferenceIds, true)) {
            return $conferenceId;
        }

        if (Conference::whereKey($conferenceId)->exists()) {
            $this->knownConferenceIds[] = $conferenceId;

            return $conferenceId;
        }

        $group = $this->espn->core("seasons/{$year}/types/2/groups/{$conferenceId}");

        if ($group === null || ! isset($group['id'])) {
            return null;
        }

        Conference::updateOrCreate(
            ['id' => $conferenceId],
            [
                'name' => $group['name'] ?? "Group {$conferenceId}",
                'short_name' => $group['shortName'] ?? null,
                'abbreviation' => $group['abbreviation'] ?? null,
                'is_conference' => (bool) ($group['isConference'] ?? false),
            ]
        );

        $this->knownConferenceIds[] = $conferenceId;

        return $conferenceId;
    }
}
