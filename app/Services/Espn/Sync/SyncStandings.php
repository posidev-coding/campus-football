<?php

namespace App\Services\Espn\Sync;

use App\Enums\StandingSource;
use App\Models\ConferenceSeason;
use App\Models\Standing;
use App\Models\Team;
use App\Services\Espn\EspnClient;
use App\Services\Espn\RecordParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Authoritative conference standings, from ESPN's actual standings endpoint.
 *
 * This replaces v3's approach wholesale. v3 had no standings sync: it picked an
 * arbitrary game the team had played (using `latest()`, which sorts by
 * created_at rather than kickoff), fetched that game's *summary*, and scraped
 * the incidental `standings` sub-object out of it. That blob only ever contains
 * the two competing teams' conferences, so non-conference, neutral-site, and
 * bowl games could never resolve — and on a miss it wrote zeros over good data.
 *
 * The real endpoint is per-conference and per-season, and returns a `records[]`
 * array per team including a `vsconf` entry with wins/losses/ties directly.
 * Verified live against the 2025 SEC.
 */
class SyncStandings
{
    public function __construct(private EspnClient $espn) {}

    /**
     * Divisions this app actually ranks.
     *
     * ESPN publishes standings for DII/DIII and NAIA too, but those cost
     * hundreds of requests per run for data no screen surfaces. Pass an empty
     * array to sync everything.
     *
     * @var list<string>
     */
    public const RANKED_CLASSIFICATIONS = ['FBS', 'FCS'];

    public function handle(int $year, int $seasonType = 2, array $classifications = self::RANKED_CLASSIFICATIONS): int
    {
        $conferences = ConferenceSeason::where('season_year', $year)
            ->whereNotNull('classification')
            ->when($classifications !== [], fn ($q) => $q->whereIn('classification', $classifications))
            ->pluck('conference_id');

        $synced = 0;

        foreach ($conferences as $conferenceId) {
            $synced += $this->syncConference($year, (int) $conferenceId, $seasonType);
        }

        return $synced;
    }

    /**
     * One conference, in one transaction.
     *
     * All-or-nothing per conference is deliberate: a partially-applied standings
     * update is worse than none, because it leaves some teams current and
     * others stale with no way to tell which is which.
     */
    public function syncConference(int $year, int $conferenceId, int $seasonType = 2): int
    {
        $body = $this->espn->core(
            "seasons/{$year}/types/{$seasonType}/groups/{$conferenceId}/standings/0",
            ttl: config('espn.cache.schedule')
        );

        // Most groups are not conferences and have no standings. Absent data is
        // an ordinary outcome, not a failure — and critically, it writes nothing.
        if ($body === null || empty($body['standings'])) {
            return 0;
        }

        $rows = [];

        foreach ($body['standings'] as $entry) {
            $row = $this->buildRow($entry, $year, $conferenceId);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        /*
         * ESPN standings can reference teams that are not in the season's team
         * list — DII/DIII payloads in particular. Dropping those rows keeps one
         * stray reference from aborting an entire conference's update, which
         * matters because the whole conference writes in a single transaction.
         */
        $rows = $this->rejectUnknownTeams($rows, $conferenceId);

        if ($rows === []) {
            Log::warning('ESPN standings payload contained no usable entries', [
                'year' => $year,
                'conference_id' => $conferenceId,
            ]);

            return 0;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                Standing::updateOrCreate(
                    [
                        'season_year' => $row['season_year'],
                        'conference_id' => $row['conference_id'],
                        'team_id' => $row['team_id'],
                        'source' => StandingSource::Espn,
                    ],
                    $row['values']
                );
            }
        });

        return count($rows);
    }

    /**
     * Build one team's row, or null if the payload cannot be trusted.
     *
     * Every value is read by name and left out of the update entirely when
     * absent. Nothing here ever substitutes a zero for missing data.
     */
    private function buildRow(array $entry, int $year, int $conferenceId): ?array
    {
        $teamId = RecordParser::teamIdFromRef($entry['team']['$ref'] ?? '');

        if ($teamId === null) {
            return null;
        }

        $records = $entry['records'] ?? [];

        $overall = RecordParser::record($records, 'total');
        $conference = RecordParser::record($records, 'vsconf');

        // A standings entry with neither an overall nor a conference record is
        // structurally wrong; skip it rather than write an empty row.
        if ($overall === null && $conference === null) {
            return null;
        }

        $values = array_filter([
            'overall_wins' => RecordParser::intStat($overall, 'wins'),
            'overall_losses' => RecordParser::intStat($overall, 'losses'),
            'overall_ties' => RecordParser::intStat($overall, 'ties'),
            'conf_wins' => RecordParser::intStat($conference, 'wins'),
            'conf_losses' => RecordParser::intStat($conference, 'losses'),
            'conf_ties' => RecordParser::intStat($conference, 'ties'),
            'win_pct' => RecordParser::floatStat($overall, 'winPercent'),
            'conf_win_pct' => RecordParser::floatStat($conference, 'winPercent'),
            'points_for' => RecordParser::intStat($overall, 'pointsFor'),
            'points_against' => RecordParser::intStat($overall, 'pointsAgainst'),
            'point_differential' => RecordParser::intStat($overall, 'pointDifferential'),
            'playoff_seed' => RecordParser::intStat($overall, 'playoffSeed'),
            'games_behind' => RecordParser::floatStat($conference, 'gamesBehind'),
            'home_record' => RecordParser::displayValue(RecordParser::record($records, 'homerecord')),
            'away_record' => RecordParser::displayValue(RecordParser::record($records, 'awayrecord')),
            'vs_ranked_record' => RecordParser::displayValue(RecordParser::record($records, 'vsaprankedteams')),
            'streak' => $this->streak($overall),
        ], fn ($value) => $value !== null);

        $values['synced_at'] = now();

        return [
            'season_year' => $year,
            'conference_id' => $conferenceId,
            'team_id' => $teamId,
            'values' => $values,
        ];
    }

    /**
     * @param  list<array>  $rows
     * @return list<array>
     */
    private function rejectUnknownTeams(array $rows, int $conferenceId): array
    {
        if ($rows === []) {
            return $rows;
        }

        $known = Team::whereIn('id', array_column($rows, 'team_id'))->pluck('id')->all();

        $kept = array_values(array_filter(
            $rows,
            fn (array $row) => in_array($row['team_id'], $known, true)
        ));

        if (($dropped = count($rows) - count($kept)) > 0) {
            Log::info('Skipped standings rows for teams not in the season roster', [
                'conference_id' => $conferenceId,
                'dropped' => $dropped,
            ]);
        }

        return $kept;
    }

    /**
     * ESPN returns streak as a signed number: 3 is W3, -2 is L2.
     */
    private function streak(?array $record): ?string
    {
        $value = RecordParser::intStat($record, 'streak');

        if ($value === null || $value === 0) {
            return null;
        }

        return ($value > 0 ? 'W' : 'L').abs($value);
    }
}
