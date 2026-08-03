<?php

namespace App\Services\Espn\Sync;

use App\Models\Game;
use App\Models\Season;
use App\Models\Venue;
use App\Models\Week;
use App\Services\Espn\EspnClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Games, from the site scoreboard rather than the core event API.
 *
 * The core API is more normalised but requires roughly eight sub-requests per
 * game (status, both scores, both teams, venue, odds, predictor) — about 7,000
 * requests for one season. The site scoreboard returns many games denormalised
 * in a single call: a full 2025 FBS season is 958 games in 9 requests.
 *
 * ESPN's `groups=80` filter restricts this to FBS, which is what the pick'em
 * cares about; pass a different group to widen it.
 */
class SyncGames
{
    public function __construct(private EspnClient $espn) {}

    /** ESPN's hard cap on scoreboard results, whatever `limit` says. */
    private const MAX_EVENTS = 1000;

    /** Days per request window. */
    private const WINDOW_DAYS = 30;

    /**
     * Windows overlap so a game on a boundary cannot fall between two requests.
     * Duplicates are free — everything upserts by ESPN event id.
     */
    private const WINDOW_OVERLAP_DAYS = 5;

    /**
     * Sync a whole season, in overlapping date windows.
     *
     * Two things force this shape. First, the obvious approach — one request
     * per week — silently truncates: week 5 of 2025 returns 25 events when the
     * real figure is far higher, and raising `limit` does not change it. Only a
     * date range returns the complete set.
     *
     * Second, a season-wide range works but decodes to a 92 MB array and peaks
     * around 138 MB, which blows PHP's default 128 MB limit. That failed as a
     * bare exit-255 with an empty log, and it would fail the same way on a
     * queue worker. Windowing keeps each payload small enough to be routine.
     *
     * Games are matched to their week by kickoff date afterwards, which is what
     * the weeks table's date-range index exists for.
     */
    public function season(int $year, int $group = 80): int
    {
        $seasons = Season::where('year', $year)->get()->keyBy('type');

        if ($seasons->isEmpty()) {
            Log::warning('Cannot sync games before the season exists', compact('year'));

            return 0;
        }

        $weeks = $this->weeksBySeason($seasons);

        $seen = [];

        foreach ($this->windows($year) as [$from, $to]) {
            $body = $this->espn->site('scoreboard', [
                'limit' => self::MAX_EVENTS,
                'dates' => "{$from}-{$to}",
                'groups' => $group,
                // Deliberately uncached: this response is tens of megabytes and
                // caching it would cost more memory than fetching it twice.
            ], ttl: 0);

            if ($body === null || empty($body['events'])) {
                continue;
            }

            if (count($body['events']) >= self::MAX_EVENTS) {
                Log::warning('Scoreboard hit the event cap; this window may be truncated', [
                    'year' => $year,
                    'window' => "{$from}-{$to}",
                ]);
            }

            foreach ($body['events'] as $event) {
                $id = (int) ($event['id'] ?? 0);

                if ($id === 0 || isset($seen[$id])) {
                    continue;
                }

                if ($this->store($event, $seasons, $weeks)) {
                    $seen[$id] = true;
                }
            }

            unset($body);
        }

        return count($seen);
    }

    /**
     * Overlapping windows spanning a season, August through the CFP final.
     *
     * @return list<array{0:string,1:string}>
     */
    private function windows(int $year): array
    {
        $cursor = CarbonImmutable::create($year, 8, 1);
        $end = CarbonImmutable::create($year + 1, 3, 1);

        $windows = [];

        while ($cursor->lessThan($end)) {
            $stop = $cursor->addDays(self::WINDOW_DAYS)->min($end);

            $windows[] = [$cursor->format('Ymd'), $stop->format('Ymd')];

            // Stop once the window reaches the end. Without this the cursor
            // rewinds by the overlap and the next window clamps to `end` again,
            // looping forever — which fails as a bare exit 255 with no output
            // at all, since the fatal is memory exhaustion inside the loop.
            if ($stop->greaterThanOrEqualTo($end)) {
                break;
            }

            $cursor = $stop->subDays(self::WINDOW_OVERLAP_DAYS);
        }

        return $windows;
    }

    /**
     * @return array<int, Collection<int, Week>>
     */
    private function weeksBySeason($seasons): array
    {
        $bySeason = [];

        foreach ($seasons as $type => $season) {
            $bySeason[$type] = Week::where('season_id', $season->id)->get();
        }

        return $bySeason;
    }

    /**
     * Match a kickoff to its week by date range.
     *
     * v3 did this lookup with `->first()->id` and no null guard, so a game
     * whose kickoff fell in a calendar gap threw and killed the run.
     */
    private function resolveWeek(iterable $weeks, CarbonImmutable $kickoff): ?Week
    {
        foreach ($weeks as $week) {
            if ($week->start_date === null || $week->end_date === null) {
                continue;
            }

            if ($kickoff->betweenIncluded($week->start_date, $week->end_date)) {
                return $week;
            }
        }

        return null;
    }

    private function store(array $event, $seasons, array $weeks): bool
    {
        $competition = $event['competitions'][0] ?? null;

        if ($competition === null || ! isset($event['id'], $event['date'])) {
            return false;
        }

        $home = $this->competitor($competition, 'home');
        $away = $this->competitor($competition, 'away');

        if ($home === null || $away === null) {
            return false;
        }

        // Each event names its own season type, so regular and postseason come
        // back in the same range request and are filed correctly.
        $seasonType = (int) ($event['season']['type'] ?? Season::REGULAR);
        $season = $seasons[$seasonType] ?? $seasons[Season::REGULAR] ?? null;

        if ($season === null) {
            return false;
        }

        $kickoff = CarbonImmutable::parse($event['date']);
        $week = $this->resolveWeek($weeks[$seasonType] ?? [], $kickoff);
        $status = $event['status'] ?? [];
        $type = $status['type'] ?? [];

        Game::updateOrCreate(
            ['id' => (int) $event['id']],
            [
                'season_id' => $season->id,
                'week_id' => $week?->id,
                'venue_id' => $this->venue($competition['venue'] ?? null),
                'kickoff_at' => $kickoff,
                /*
                 * Day of week in Eastern, computed here rather than derived in
                 * SQL. Contests may only slate Saturday games, and a CFB season
                 * straddles EDT and EST — so this must go through a named zone,
                 * once, at write time.
                 */
                'kickoff_day' => $kickoff->setTimezone(config('cfb.timezone'))->format('D'),
                'name' => $event['name'] ?? 'Unknown matchup',
                'short_name' => $event['shortName'] ?? null,
                'neutral_site' => (bool) ($competition['neutralSite'] ?? false),
                'conference_game' => (bool) ($competition['conferenceCompetition'] ?? false),
                'attendance' => $competition['attendance'] ?? null,
                'broadcasts' => $this->broadcasts($competition),

                'home_team_id' => (int) $home['id'],
                'home_score' => (int) ($home['score'] ?? 0),
                'home_rank' => $this->rank($home),
                'home_record' => $this->record($home),
                'home_line_scores' => $this->lineScores($home),

                'away_team_id' => (int) $away['id'],
                'away_score' => (int) ($away['score'] ?? 0),
                'away_rank' => $this->rank($away),
                'away_record' => $this->record($away),
                'away_line_scores' => $this->lineScores($away),

                'status' => $type['state'] ?? null,
                'status_detail' => $type['shortDetail'] ?? null,
                'period' => (int) ($status['period'] ?? 0),
                'clock' => $status['displayClock'] ?? null,
                'completed' => (bool) ($type['completed'] ?? false),
            ]
        );

        return true;
    }

    private function competitor(array $competition, string $side): ?array
    {
        foreach ($competition['competitors'] ?? [] as $competitor) {
            if (($competitor['homeAway'] ?? null) === $side) {
                return $competitor;
            }
        }

        return null;
    }

    /**
     * ESPN uses 99 as its unranked sentinel. Storing that verbatim — as v3 did —
     * means every ordering and "is this team ranked" check has to know the magic
     * number. Null is the honest representation.
     */
    private function rank(array $competitor): ?int
    {
        $rank = $competitor['curatedRank']['current'] ?? null;

        return ($rank === null || (int) $rank >= 99) ? null : (int) $rank;
    }

    private function record(array $competitor): ?string
    {
        foreach ($competitor['records'] ?? [] as $record) {
            if (($record['type'] ?? null) === 'total') {
                return $record['summary'] ?? null;
            }
        }

        return null;
    }

    /**
     * @return list<int>|null
     */
    private function lineScores(array $competitor): ?array
    {
        $scores = $competitor['linescores'] ?? null;

        if (empty($scores)) {
            return null;
        }

        return array_map(fn (array $line) => (int) ($line['value'] ?? 0), $scores);
    }

    /**
     * @return list<string>|null
     */
    private function broadcasts(array $competition): ?array
    {
        $names = [];

        foreach ($competition['broadcasts'] ?? [] as $broadcast) {
            foreach ($broadcast['names'] ?? [] as $name) {
                $names[] = $name;
            }
        }

        return $names === [] ? null : array_values(array_unique($names));
    }

    private function venue(?array $venue): ?int
    {
        if ($venue === null || ! isset($venue['id'])) {
            return null;
        }

        Venue::updateOrCreate(
            ['id' => (int) $venue['id']],
            [
                'name' => $venue['fullName'] ?? "Venue {$venue['id']}",
                'city' => $venue['address']['city'] ?? null,
                'state' => $venue['address']['state'] ?? null,
                'capacity' => $venue['capacity'] ?? null,
                'indoor' => (bool) ($venue['indoor'] ?? false),
            ]
        );

        return (int) $venue['id'];
    }
}
