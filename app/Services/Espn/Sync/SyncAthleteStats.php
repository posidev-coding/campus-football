<?php

namespace App\Services\Espn\Sync;

use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\AthleteSeasonStat;
use App\Models\Game;
use App\Services\Espn\EspnClient;
use Illuminate\Support\Facades\Cache;

/**
 * Per-athlete game logs and season totals — polled per athlete, never in bulk.
 *
 * This is the one player feed that is genuinely per-athlete, so bulk syncing it
 * would cost one request for each of ~34,000 players. Nothing justifies that:
 * the overwhelming majority are never looked at, and the ones who are get
 * looked at repeatedly.
 *
 * So opening a player page DISPATCHES a refresh rather than performing one —
 * see FetchAthleteGameLog. The page renders what we already hold and the job
 * fills in behind it; nobody waits on an upstream round trip. Popular players
 * stay warm, and the long tail costs one request the first time anyone cares.
 */
class SyncAthleteStats
{
    /**
     * How long a log stays fresh on a day with no college football on it.
     */
    public const FRESH_FOR = 86400;

    /**
     * And on a Saturday, when the numbers are actually moving.
     *
     * Fifteen minutes is a per-ATHLETE ceiling, so it costs four requests an
     * hour for a player somebody is watching and nothing at all for the rest.
     * The live scoreboard tier already refreshes every minute for the whole
     * slate; this sits an order of magnitude below that and well inside the
     * client's 240/min allowance.
     */
    public const FRESH_FOR_GAMEDAY = 900;

    /**
     * A ceiling on the in-flight lock, not a freshness window.
     *
     * The lock is RELEASED when the fetch finishes; this only covers a process
     * that dies holding it. It was once a plain `Cache::add` marker with this
     * TTL and no release, which made it a 60-second freshness gate wearing an
     * in-flight label — and that silently swallowed a hand-asked refresh made
     * within a minute of the last one. The button did nothing, the page had no
     * "it came back" signal to wait for, and it spun until its own ceiling.
     */
    private const IN_FLIGHT = 60;

    public function __construct(private EspnClient $espn) {}

    /**
     * Whether this athlete's log is due another poll.
     *
     * A null timestamp means we have NEVER asked, which is not the same as
     * "has no stats" — most athletes never record any, and reading emptiness as
     * staleness would re-dispatch on every view of every one of them.
     */
    public function isStale(Athlete $athlete): bool
    {
        if ($athlete->game_log_fetched_at === null) {
            return true;
        }

        return $athlete->game_log_fetched_at->lt(now()->subSeconds($this->window()));
    }

    /**
     * The polling window for right now.
     *
     * Saturday is decided in the app's own timezone, never UTC. A UTC Saturday
     * opens at 8pm Friday Eastern, which would put Friday night's games on the
     * gameday cadence and Saturday night's on the 24-hour one — precisely
     * inverted, and only visible in the evening.
     */
    public function window(): int
    {
        return now(config('cfb.timezone'))->isSaturday()
            ? self::FRESH_FOR_GAMEDAY
            : self::FRESH_FOR;
    }

    /**
     * Fetch and persist an athlete's game log.
     *
     * Returns whether ESPN ANSWERED — not whether it had anything to say. The
     * caller stamps `game_log_fetched_at` on true, so a player with a genuinely
     * empty log is marked as asked and stops being re-queued, while a transient
     * failure leaves the timestamp alone and is retried on the next view. Same
     * rule as the article story sync: a 500 must not permanently demote a
     * player to "no stats".
     */
    public function refreshGameLog(int $athleteId): bool
    {
        // Held only for the duration of the fetch, so two viewers arriving at
        // once collapse into one request while a click a minute later does not
        // get thrown away. Redundant repeats on the unforced path are already
        // prevented by the job's own staleness check.
        $lock = Cache::lock("athlete:gamelog:{$athleteId}", self::IN_FLIGHT);

        if (! $lock->get()) {
            return false;
        }

        try {
            $body = $this->espn->web("athletes/{$athleteId}/gamelog", ttl: 0);

            // No payload at all is a failed request, and must not be recorded
            // as an answer. An answer with no events is a real "has none".
            if ($body === null) {
                return false;
            }

            $names = $body['names'] ?? [];

            foreach ($body['seasonTypes'] ?? [] as $seasonType) {
                foreach ($seasonType['categories'] ?? [] as $category) {
                    $categoryName = $category['type'] ?? $category['displayName'] ?? 'general';

                    foreach ($category['events'] ?? [] as $event) {
                        if ($names !== []) {
                            $this->storeEvent($athleteId, $event, $names, $categoryName);
                        }
                    }
                }
            }

            return true;
        } finally {
            $lock->release();
        }
    }

    private function storeEvent(int $athleteId, array $event, array $names, string $category): int
    {
        $gameId = isset($event['eventId']) ? (int) $event['eventId'] : null;

        if ($gameId === null || empty($event['stats'])) {
            return 0;
        }

        // The log spans every game a player has appeared in, including seasons
        // and opponents outside what we ingest. Skip rather than break the FK.
        if (! Game::whereKey($gameId)->exists()) {
            return 0;
        }

        // `names` and `stats` are parallel arrays — zip them into something
        // addressable by name rather than by index.
        $stats = [];
        $columns = [];

        foreach ($names as $index => $name) {
            if (array_key_exists($index, $event['stats'])) {
                $stats[$name] = $event['stats'][$index];
                $columns[] = $name;
            }
        }

        if ($stats === []) {
            return 0;
        }

        AthleteGameStat::updateOrCreate(
            ['athlete_id' => $athleteId, 'game_id' => $gameId, 'category' => $category],
            [
                'team_id' => Athlete::find($athleteId)?->latestSeason?->team_id,
                'stats' => $stats,
                /*
                 * The column order, stored separately and deliberately.
                 *
                 * MySQL's JSON type does not preserve object key order — it
                 * normalises keys on write, so reading `array_keys($stats)`
                 * back gives an arbitrary order and the game log would render
                 * its columns scrambled. JSON *arrays* do keep their order, so
                 * the ordering lives here.
                 */
                'display_stats' => $columns,
            ]
        );

        return 1;
    }

    /**
     * Career season-by-season totals. One request.
     */
    public function refreshCareer(int $athleteId): bool
    {
        // ttl: 0 here and on the per-season refs — one-shot payloads over
        // tens of thousands of athletes. Cached 12h they crowd the Redis DB
        // holding the ESPN limiter and budget counters, whose eviction
        // fails OPEN.
        $body = $this->espn->core("athletes/{$athleteId}/statisticslog", ttl: 0);

        if ($body === null || empty($body['entries'])) {
            return false;
        }

        $written = 0;

        foreach ($body['entries'] as $entry) {
            $year = $this->seasonYear($entry);

            if ($year === null) {
                continue;
            }

            foreach ($entry['statistics'] ?? [] as $statistic) {
                $categories = $this->espn->ref($statistic['statistics']['$ref'] ?? '', ttl: 0);

                foreach ($categories['splits']['categories'] ?? [] as $category) {
                    $name = $category['name'] ?? null;

                    if ($name === null || empty($category['stats'])) {
                        continue;
                    }

                    $stats = [];

                    foreach ($category['stats'] as $stat) {
                        if (isset($stat['name'])) {
                            $stats[$stat['name']] = $stat['displayValue'] ?? $stat['value'] ?? null;
                        }
                    }

                    AthleteSeasonStat::updateOrCreate(
                        [
                            'athlete_id' => $athleteId,
                            'season_year' => $year,
                            'season_type' => 2,
                            'category' => $name,
                        ],
                        ['stats' => $stats]
                    );

                    $written++;
                }
            }
        }

        return $written > 0;
    }

    private function seasonYear(array $entry): ?int
    {
        if (isset($entry['season']['year'])) {
            return (int) $entry['season']['year'];
        }

        // Otherwise the year is only in the $ref path.
        $ref = $entry['season']['$ref'] ?? '';

        return preg_match('#/seasons/(\d{4})#', $ref, $m) ? (int) $m[1] : null;
    }
}
