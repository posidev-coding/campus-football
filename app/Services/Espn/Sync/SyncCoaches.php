<?php

namespace App\Services\Espn\Sync;

use App\Models\Coach;
use App\Models\CoachTeamSeason;
use App\Models\Team;
use App\Services\Espn\EspnClient;
use Illuminate\Support\Facades\Http;

/**
 * One coach's full record, from the core API.
 *
 * The roster feed names a coach and nothing else. This fills in the person —
 * birthplace, career record — and the TENURE: one row per season per school,
 * each with that season's record, which is how a move between programs is
 * captured (Riley: 2021 Oklahoma, 2025 USC, verified live).
 *
 * Costs about 2 + 2N requests for an N-season coach: the coach document and
 * career record, then each season's coach document (whose `team.$ref` names
 * that season's school without another request) and regular-season record.
 * Roughly 3,000 requests for a one-time backfill of the current 136 head
 * coaches; after that only the current season changes.
 *
 * There is no coach headshot endpoint. `players/full/{id}.png` resolves for
 * maybe a third of coaches — incidentally, where a coach's id matches their
 * old player id — so it is probed with one HEAD against the CDN (not the API,
 * so not against the rate ceiling) and stored only on a 200. Everything else
 * about the row must look right without one.
 */
class SyncCoaches
{
    /**
     * ESPN writes a coach's birthplace with the FULL state name — "Montgomery,
     * Alabama" — while athletes carry two-letter codes. Normalized on write so
     * a search list never shows both formats side by side.
     */
    private const STATES = [
        'Alabama' => 'AL', 'Alaska' => 'AK', 'Arizona' => 'AZ', 'Arkansas' => 'AR',
        'California' => 'CA', 'Colorado' => 'CO', 'Connecticut' => 'CT', 'Delaware' => 'DE',
        'Florida' => 'FL', 'Georgia' => 'GA', 'Hawaii' => 'HI', 'Idaho' => 'ID',
        'Illinois' => 'IL', 'Indiana' => 'IN', 'Iowa' => 'IA', 'Kansas' => 'KS',
        'Kentucky' => 'KY', 'Louisiana' => 'LA', 'Maine' => 'ME', 'Maryland' => 'MD',
        'Massachusetts' => 'MA', 'Michigan' => 'MI', 'Minnesota' => 'MN', 'Mississippi' => 'MS',
        'Missouri' => 'MO', 'Montana' => 'MT', 'Nebraska' => 'NE', 'Nevada' => 'NV',
        'New Hampshire' => 'NH', 'New Jersey' => 'NJ', 'New Mexico' => 'NM', 'New York' => 'NY',
        'North Carolina' => 'NC', 'North Dakota' => 'ND', 'Ohio' => 'OH', 'Oklahoma' => 'OK',
        'Oregon' => 'OR', 'Pennsylvania' => 'PA', 'Rhode Island' => 'RI', 'South Carolina' => 'SC',
        'South Dakota' => 'SD', 'Tennessee' => 'TN', 'Texas' => 'TX', 'Utah' => 'UT',
        'Vermont' => 'VT', 'Virginia' => 'VA', 'Washington' => 'WA', 'West Virginia' => 'WV',
        'Wisconsin' => 'WI', 'Wyoming' => 'WY', 'District of Columbia' => 'DC', 'Puerto Rico' => 'PR',
    ];

    public function __construct(private EspnClient $espn) {}

    /**
     * Sync one coach. Returns false when ESPN has no document for them —
     * callers must skip, never write defaults.
     */
    public function handle(int $coachId, bool $currentSeasonOnly = false): bool
    {
        $body = $this->espn->core("coaches/{$coachId}");

        if ($body === null) {
            return false;
        }

        $name = trim(($body['firstName'] ?? '').' '.($body['lastName'] ?? ''));

        $coach = Coach::updateOrCreate(['id' => $coachId], array_filter([
            'first_name' => $body['firstName'] ?? null,
            'last_name' => $body['lastName'] ?? null,
            'display_name' => $name !== '' ? $name : null,
            'date_of_birth' => isset($body['dateOfBirth']) ? substr($body['dateOfBirth'], 0, 10) : null,
            'birth_city' => $body['birthPlace']['city'] ?? null,
            'birth_state' => $this->stateCode($body['birthPlace']['state'] ?? null),
            'birth_country' => $body['birthPlace']['country'] ?? null,
            'experience_years' => $body['experience'] ?? null,
        ], fn ($value) => $value !== null));

        $this->storeCareerRecord($coach);
        $this->storeHeadshot($coach);

        $seasons = $this->seasonYears($body['coachSeasons'] ?? []);

        if ($currentSeasonOnly && $seasons !== []) {
            $seasons = [max($seasons)];
        }

        foreach ($seasons as $year) {
            $this->storeSeason($coach, $year);
        }

        return true;
    }

    /**
     * Career totals from `record/0` — the "Total" record, summary "117-21-0".
     */
    private function storeCareerRecord(Coach $coach): void
    {
        $record = $this->espn->core("coaches/{$coach->id}/record/0");

        if ($record === null) {
            return;
        }

        $stats = collect($record['stats'] ?? [])->keyBy('name');

        $wins = $stats['wins']['value'] ?? null;
        $losses = $stats['losses']['value'] ?? null;

        if ($wins === null || $losses === null) {
            return;
        }

        $coach->fill([
            'career_wins' => (int) $wins,
            'career_losses' => (int) $losses,
            'career_ties' => (int) ($stats['ties']['value'] ?? 0),
        ]);

        if ($coach->isDirty()) {
            $coach->save();
        }
    }

    /**
     * One tenure row: the season's school and its regular-season record.
     *
     * The team comes out of the season document's `team.$ref` URL — the id is
     * IN the ref, so resolving it would be a wasted request. Only teams we
     * already know get a row; the sync never invents a team from a URL.
     */
    private function storeSeason(Coach $coach, int $year): void
    {
        $body = $this->espn->core("seasons/{$year}/coaches/{$coach->id}");

        if ($body === null) {
            return;
        }

        $teamId = $this->teamIdFromRef($body['team']['$ref'] ?? null);

        if ($teamId === null || ! Team::whereKey($teamId)->exists()) {
            return;
        }

        $values = ['experience' => $body['experience'] ?? null];

        $record = $this->espn->core("seasons/{$year}/types/2/coaches/{$coach->id}/record");

        if (is_array($record) && preg_match('/^(\d+)-(\d+)(?:-(\d+))?/', $record['summary'] ?? '', $parts)) {
            $values += [
                'wins' => (int) $parts[1],
                'losses' => (int) $parts[2],
                'ties' => (int) ($parts[3] ?? 0),
            ];
        }

        CoachTeamSeason::updateOrCreate(
            ['coach_id' => $coach->id, 'team_id' => $teamId, 'season_year' => $year],
            $values,
        );
    }

    /**
     * One HEAD against the CDN, stored only on a 200. Not retried and not
     * throttled — it is a static asset host, not the API.
     */
    private function storeHeadshot(Coach $coach): void
    {
        if ($coach->headshot_url !== null) {
            return;
        }

        $url = "https://a.espncdn.com/i/headshots/college-football/players/full/{$coach->id}.png";

        try {
            if (Http::timeout(5)->head($url)->successful()) {
                $coach->update(['headshot_url' => $url]);
            }
        } catch (\Throwable) {
            // A CDN hiccup is not worth failing a coach over; the next run
            // probes again because the column is still null.
        }
    }

    /**
     * @param  list<array{'$ref'?: string}>  $refs
     * @return list<int>
     */
    private function seasonYears(array $refs): array
    {
        return collect($refs)
            ->map(fn (array $ref) => preg_match('#/seasons/(\d{4})/#', $ref['$ref'] ?? '', $m) ? (int) $m[1] : null)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function teamIdFromRef(?string $ref): ?int
    {
        if ($ref !== null && preg_match('#/teams/(\d+)#', $ref, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function stateCode(?string $state): ?string
    {
        if ($state === null) {
            return null;
        }

        // Already a code — some rows may arrive normalized.
        if (strlen($state) === 2) {
            return strtoupper($state);
        }

        return self::STATES[$state] ?? $state;
    }
}
