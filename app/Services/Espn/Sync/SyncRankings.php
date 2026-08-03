<?php

namespace App\Services\Espn\Sync;

use App\Models\Ranking;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Services\Espn\EspnClient;
use Illuminate\Support\Facades\DB;

/**
 * AP, Coaches, CFP and the divisional polls.
 *
 * One request returns every poll for a week — five of them, 25 teams each — so
 * this is the cheapest feed in the app. Historical weeks are addressable, which
 * makes a full-season backfill about 16 requests.
 *
 * The CFP poll only exists from early November, and the divisional polls come
 * and go; absent polls are simply not written rather than treated as an error.
 */
class SyncRankings
{
    public function __construct(private EspnClient $espn) {}

    /**
     * Every week of a season. ~16 requests.
     */
    public function season(int $year, int $seasonType = Season::REGULAR): int
    {
        $season = Season::where('year', $year)->where('type', $seasonType)->first();

        if ($season === null) {
            return 0;
        }

        $synced = 0;

        foreach ($season->weeks()->orderBy('number')->get() as $week) {
            $synced += $this->week($year, $week, $seasonType);
        }

        return $synced;
    }

    /**
     * One week's polls. One request.
     */
    public function week(int $year, ?Week $week = null, int $seasonType = Season::REGULAR): int
    {
        $season = Season::where('year', $year)->where('type', $seasonType)->first();

        if ($season === null) {
            return 0;
        }

        $query = ['season' => $year, 'seasontype' => $seasonType];

        if ($week !== null) {
            $query['week'] = $week->number;
        }

        $body = $this->espn->site('rankings', $query, ttl: config('espn.cache.schedule'));

        if ($body === null || empty($body['rankings'])) {
            return 0;
        }

        $synced = 0;

        foreach ($body['rankings'] as $poll) {
            $synced += $this->storePoll($poll, $season, $week);
        }

        return $synced;
    }

    private function storePoll(array $poll, Season $season, ?Week $week): int
    {
        $type = $poll['type'] ?? null;

        if ($type === null || empty($poll['ranks'])) {
            return 0;
        }

        $rows = [];

        foreach ($poll['ranks'] as $entry) {
            $teamId = isset($entry['team']['id']) ? (int) $entry['team']['id'] : null;

            if ($teamId === null || ! isset($entry['current'])) {
                continue;
            }

            $rows[] = [
                'team_id' => $teamId,
                'rank' => (int) $entry['current'],
                'previous_rank' => isset($entry['previous']) && $entry['previous'] > 0
                    ? (int) $entry['previous']
                    : null,
                'points' => (int) ($entry['points'] ?? 0),
                'first_place_votes' => (int) ($entry['firstPlaceVotes'] ?? 0),
                'record' => $entry['recordSummary'] ?? null,
                'trend' => $entry['trend'] ?? null,
            ];
        }

        // Polls reference teams across every division, and we only carry the
        // ones the season roster gave us. Drop the rest rather than let a
        // foreign key abort the whole poll.
        $known = Team::whereIn('id', array_column($rows, 'team_id'))->pluck('id')->all();
        $rows = array_filter($rows, fn (array $r) => in_array($r['team_id'], $known, true));

        if ($rows === []) {
            return 0;
        }

        DB::transaction(function () use ($rows, $season, $week, $type) {
            foreach ($rows as $row) {
                Ranking::updateOrCreate(
                    [
                        'season_id' => $season->id,
                        'week_id' => $week?->id,
                        'poll' => $type,
                        'team_id' => $row['team_id'],
                    ],
                    $row
                );
            }
        });

        return count($rows);
    }
}
