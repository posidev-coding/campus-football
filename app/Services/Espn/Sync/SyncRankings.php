<?php

namespace App\Services\Espn\Sync;

use App\Enums\Poll;
use App\Models\Ranking;
use App\Models\Season;
use App\Models\Team;
use App\Models\Week;
use App\Services\CfbCalendar;
use App\Services\Espn\EspnClient;
use App\Services\Espn\RecordParser;
use Illuminate\Support\Facades\DB;

/**
 * AP, Coaches, CFP and the divisional polls.
 *
 * Reads the CORE api's per-week rankings collection rather than the site
 * endpoint, for one decisive reason: the site endpoint never returns the CFP
 * rankings at all. Verified live — asking it for week 16 of 2025 returns the
 * same five polls it returns for week 1, and its `type=` parameter is silently
 * ignored. The core collection returns six polls in week 11 and the CFP among
 * them.
 *
 * Poll availability genuinely varies through the season, which is the whole
 * point of reading a per-week collection rather than assuming a fixed list:
 *
 *   week 10   AP, Coaches, FCS, Div II, Div III
 *   week 11   the same five plus CFP Rankings
 *   week 16   CFP Rankings, CFP Seedings, AP, Coaches — divisional polls gone
 *
 * Cost is two requests per week plus one per poll, so a full season is modest.
 */
class SyncRankings
{
    public function __construct(private EspnClient $espn) {}

    /**
     * A whole season's polls, across all three types that publish them.
     *
     * The polls do not live only in the regular season. Verified live: type 1
     * week 1 carries the Preseason poll and type 3 week 1 carries the Final
     * Rankings, each labelled as such by ESPN's `occurrence`. Syncing only the
     * regular season loses both ends of the year.
     */
    public function season(int $year, ?int $seasonType = null): int
    {
        $types = $seasonType !== null
            ? [$seasonType]
            : [Season::PRESEASON, Season::REGULAR, Season::POSTSEASON];

        $synced = 0;

        foreach ($types as $type) {
            $season = Season::where('year', $year)->where('type', $type)->first();

            if ($season === null) {
                continue;
            }

            foreach ($season->weeks()->orderBy('number')->get() as $week) {
                $synced += $this->week($year, $week, $type);
            }
        }

        return $synced;
    }

    /**
     * The current week's polls, and the next week's once they are out.
     *
     * What the weekly schedule should call. `season()` re-reads all 18 weeks
     * every run — about 126 requests, twice a week, to learn ONE new week of
     * polls. Published rankings never change retroactively, so re-syncing week
     * 3 in November is 125 wasted requests and a pointless write pass against a
     * scale-to-zero database.
     *
     * TWO weeks, because the two Sunday-to-Tuesday releases land on opposite
     * sides of a week boundary (verified live against 2025):
     *
     *   AP, Coaches  posted Sunday afternoon, filed under the NEXT week, which
     *                opens Monday. A run on Sunday evening that asks only for
     *                the week it is inside re-reads a poll it already holds,
     *                and the new one waits until Tuesday.
     *   CFP          posted Tuesday around 22:00-23:00 ET, filed under the
     *                CURRENT week. The 19:00 Tuesday run is too early for it,
     *                so the Sunday re-read of the week we are inside is the
     *                only scheduled pass that ever collects it.
     *
     * So: the week we are inside, plus CfbCalendar::pollWeek() when that is a
     * different week. Six more requests on a Sunday, none on a Tuesday. A poll
     * week ESPN has not published yet comes back empty and writes nothing; it
     * is never stood in for by the week before.
     *
     * Falls back to the latest week that has games when neither resolves, so
     * an off-calendar run still refreshes something meaningful rather than
     * nothing.
     */
    public function current(?int $year = null): int
    {
        $calendar = app(CfbCalendar::class);
        $year ??= $calendar->currentYear();

        $weeks = collect([$calendar->week(), $calendar->pollWeek()])
            ->filter()
            ->unique('id');

        if ($weeks->isEmpty()) {
            $weekId = $calendar->defaultWeekId($year);
            $weeks = collect([$weekId ? Week::find($weekId) : null])->filter();
        }

        $synced = 0;

        foreach ($weeks as $week) {
            $season = Season::find($week->season_id);

            // A poll week a day ahead can cross into another season's calendar
            // (December into the postseason, January into next year). Only the
            // season that was asked for is synced, under its own type.
            if ($season === null || (int) $season->year !== $year) {
                continue;
            }

            $synced += $this->week($year, $week, (int) $season->type);
        }

        return $synced;
    }

    /**
     * One week's polls.
     */
    public function week(int $year, Week $week, int $seasonType = Season::REGULAR): int
    {
        $season = Season::where('year', $year)->where('type', $seasonType)->first();

        if ($season === null) {
            return 0;
        }

        $body = $this->espn->core(
            "seasons/{$year}/types/{$seasonType}/weeks/{$week->number}/rankings",
            ttl: config('espn.cache.schedule')
        );

        if ($body === null || empty($body['items'])) {
            return 0;
        }

        $synced = 0;

        foreach ($body['items'] as $item) {
            $poll = $this->espn->ref($item['$ref'] ?? '', ttl: config('espn.cache.schedule'));

            if ($poll !== null) {
                $synced += $this->storePoll($poll, $season, $week);
            }
        }

        return $synced;
    }

    private function storePoll(array $poll, Season $season, Week $week): int
    {
        // Keyed on ESPN's numeric id, not its `type` — Division II and Division
        // III both report type "afca" and would otherwise collapse together.
        $key = Poll::fromEspnId($poll['id'] ?? 0);

        if ($key === null || empty($poll['ranks'])) {
            return 0;
        }

        $rows = [];

        foreach ($poll['ranks'] as $entry) {
            $teamId = RecordParser::teamIdFromRef($entry['team']['$ref'] ?? '');

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
                // The core payload calls this `record`; the site one called it
                // `recordSummary`. Read both rather than assume.
                'record' => $this->record($entry),
                'trend' => $entry['trend'] ?? null,
            ];
        }

        // Polls reference teams across every division and we only carry the
        // ones the season roster gave us. Drop the rest rather than let a
        // foreign key abort the whole poll.
        $known = Team::whereIn('id', array_column($rows, 'team_id'))->pluck('id')->all();
        $rows = array_values(array_filter($rows, fn (array $r) => in_array($r['team_id'], $known, true)));

        if ($rows === []) {
            return 0;
        }

        DB::transaction(function () use ($rows, $season, $week, $key) {
            foreach ($rows as $row) {
                Ranking::updateOrCreate(
                    [
                        'season_id' => $season->id,
                        'week_id' => $week->id,
                        'poll' => $key->value,
                        'team_id' => $row['team_id'],
                    ],
                    $row
                );
            }
        });

        return count($rows);
    }

    private function record(array $entry): ?string
    {
        $record = $entry['record'] ?? $entry['recordSummary'] ?? null;

        if (is_array($record)) {
            return $record['summary'] ?? $record['displayValue'] ?? null;
        }

        return is_string($record) ? $record : null;
    }
}
