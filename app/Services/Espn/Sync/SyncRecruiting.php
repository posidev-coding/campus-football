<?php

namespace App\Services\Espn\Sync;

use App\Models\Athlete;
use App\Models\Position;
use App\Models\Recruit;
use App\Models\RecruitSchool;
use App\Models\Team;
use App\Services\Espn\EspnClient;
use Illuminate\Support\Carbon;

/**
 * High school recruiting classes.
 *
 * The path 404s on every obvious guess and only resolves at
 * `.../leagues/college-football/recruiting/{year}/athletes`. Twenty-three
 * classes are published, 2006 through 2028.
 *
 * This used to cost ~5,200 requests for one class and was capped at 1,000
 * prospects because of it. Two things were wrong, both measured:
 *
 *   - the collection's default page is 25 and nothing asked for more, so the
 *     table ended up holding 25 of 5,193 prospects;
 *   - every item already carries its whole document alongside its `$ref` — the
 *     key sets are identical — so following the ref bought nothing.
 *
 * Asking for 1,000 a page and reading the item in place makes a full class SIX
 * requests. There is no reason to cap it any more.
 *
 * Note the ceiling: `limit=2000` is silently ignored and serves 25. See
 * EspnClient::MAX_PAGE_SIZE.
 */
class SyncRecruiting
{
    /** Kept for callers that still want the top of a class; 0 is everything. */
    public const DEFAULT_LIMIT = 0;

    /** @var array<int, true> */
    private array $teamIds = [];

    /** @var array<int, true> */
    private array $positionIds = [];

    public function __construct(private EspnClient $espn) {}

    public function handle(int $class, int $limit = self::DEFAULT_LIMIT): int
    {
        // Loaded once per class rather than per prospect. The old code ran a
        // Team::exists() for every school on every recruit, which at 27,000
        // prospects averaging ten schools each is ~270,000 queries.
        $this->teamIds = Team::pluck('id')->flip()->map(fn () => true)->all();
        $this->positionIds = Position::pluck('id')->flip()->map(fn () => true)->all();

        $synced = 0;

        foreach ($this->espn->paginate("recruiting/{$class}/athletes", perPage: 1000, inline: true) as $recruit) {
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
        $espnAthleteId = isset($athlete['alternateId']) ? (int) $athlete['alternateId'] : null;

        $recruit = Recruit::updateOrCreate(
            ['espn_id' => (int) $athlete['id'], 'recruiting_class' => $class],
            [
                // `alternateId` is the athlete id they carry once they reach a
                // college roster, which is what links a prospect to a player.
                // Stored raw as well, so a later roster sync can make the link
                // for a prospect we did not hold at ingest time.
                'espn_athlete_id' => $espnAthleteId,
                'athlete_id' => $this->linkedAthleteId($espnAthleteId),
                'display_name' => $name,
                // Both halves stored because search indexes both: a prefix
                // matches from the start of a field, so without `last_name` a
                // surname finds nobody.
                'first_name' => $athlete['firstName'] ?? null,
                'last_name' => $athlete['lastName'] ?? null,
                'grade' => isset($payload['grade']) ? (int) $payload['grade'] : null,
                'national_rank' => $attributes['rank'] ?? null,
                'position_rank' => $attributes['positionRank'] ?? null,
                'state_rank' => $attributes['stateRank'] ?? null,
                'region_rank' => $attributes['regionRank'] ?? null,
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

        $this->storeSchools($recruit, $payload['schools'] ?? [], $class);

        return true;
    }

    /**
     * The schools that were in on a prospect.
     *
     * One upsert for the whole list rather than a row at a time — a top
     * prospect carries up to 44 of these, and 27,000 prospects at ten each is
     * 270,000 rows across a backfill.
     */
    private function storeSchools(Recruit $recruit, array $schools, int $class): void
    {
        $rows = [];
        $now = now();

        foreach ($schools as $school) {
            if (! preg_match('#/teams/(\d+)#', $school['team']['$ref'] ?? '', $m)) {
                continue;
            }

            $teamId = (int) $m[1];

            $rows[$teamId] = [
                'recruit_id' => $recruit->id,
                // ESPN's id, always. This is the upsert key: `team_id` is
                // nullable and MySQL never matches NULL to NULL in a unique
                // index, so keying on it re-inserted every unheld school on
                // each weekly run.
                'espn_team_id' => $teamId,
                // Kept even when we do not carry the team: an interest list
                // that dropped every school outside our set would misreport
                // how many were in on him.
                'team_id' => isset($this->teamIds[$teamId]) ? $teamId : null,
                'status' => $school['status']['description'] ?? null,
                'visited_on' => $this->visitDate($school['visit'] ?? null, $class),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        RecruitSchool::upsert(
            array_values($rows),
            ['recruit_id', 'espn_team_id'],
            ['team_id', 'status', 'visited_on', 'updated_at']
        );
    }

    /**
     * A visit date, or null if it cannot be true.
     *
     * Seven rows in the 2026 class carry the year 2205 — an ESPN typo for 2025.
     * Dropping it is better than guessing the intended year, and better than
     * printing a visit two centuries out next to a real one.
     */
    private function visitDate(?string $visit, int $class): ?string
    {
        if ($visit === null) {
            return null;
        }

        $date = Carbon::parse($visit);

        return $date->year >= $class - 3 && $date->year <= $class + 1
            ? $date->toDateString()
            : null;
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

        /*
         * `fortyYrdDash`, `threeConeDrill` and `twentyYrdShuttle` also live in
         * here and are deliberately not read: every one of 200 sampled
         * prospects carries the sentinel 99. Same shape as ESPN's curatedRank
         * — a number that means "no data".
         */
        return $values;
    }

    private function linkedAthleteId(?int $espnAthleteId): ?int
    {
        if ($espnAthleteId === null) {
            return null;
        }

        // Only link when we actually have that player, so the FK holds. Nearly
        // every 2021 prospect resolves; almost no 2026 one does, because they
        // have not enrolled yet.
        return Athlete::whereKey($espnAthleteId)->exists() ? $espnAthleteId : null;
    }

    /**
     * The school a prospect signed with, taken from the schools list rather
     * than assumed to be the first entry — that list carries every school in
     * contention, not just the commitment.
     */
    private function committedTeamId(array $payload): ?int
    {
        $signedStatus = $payload['status']['id'] ?? null;

        // An Undecided prospect has status id 0, which every other school in
        // the list also carries — matching on it would pick one at random.
        if (! $signedStatus) {
            return null;
        }

        foreach ($payload['schools'] ?? [] as $school) {
            if (($school['status']['id'] ?? null) !== $signedStatus) {
                continue;
            }

            if (! preg_match('#/teams/(\d+)#', $school['team']['$ref'] ?? '', $m)) {
                continue;
            }

            $teamId = (int) $m[1];

            if (isset($this->teamIds[$teamId])) {
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

        if (! isset($this->positionIds[$id])) {
            Position::firstOrCreate(
                ['id' => $id],
                [
                    'name' => $position['displayName'] ?? $position['abbreviation'] ?? "Position {$id}",
                    'abbreviation' => $position['abbreviation'] ?? null,
                ]
            );

            $this->positionIds[$id] = true;
        }

        return $id;
    }
}
