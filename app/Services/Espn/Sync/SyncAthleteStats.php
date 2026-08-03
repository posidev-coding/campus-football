<?php

namespace App\Services\Espn\Sync;

use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\AthleteSeasonStat;
use App\Models\Game;
use App\Services\Espn\EspnClient;
use Illuminate\Support\Facades\Cache;

/**
 * Per-athlete game logs and season totals — fetched on demand, never in bulk.
 *
 * This is the one player feed that is genuinely per-athlete, so bulk syncing it
 * would cost one request for each of ~16,000 players. Nothing justifies that:
 * the overwhelming majority of players are never looked at, and the ones who
 * are get looked at repeatedly.
 *
 * So the player page asks for a log when someone opens it, behind a cache lock
 * that collapses concurrent viewers into a single upstream request. Popular
 * players end up warm; the long tail costs nothing until someone cares.
 */
class SyncAthleteStats
{
    /** How long a fetched log is considered fresh. */
    private const FRESH_FOR = 1800;

    public function __construct(private EspnClient $espn) {}

    /**
     * Fetch and persist an athlete's game log if it is not already fresh.
     *
     * Returns true when data was written, false when it was already fresh, the
     * fetch failed, or another process is already doing it.
     */
    public function refreshGameLog(int $athleteId): bool
    {
        $key = "athlete:gamelog:{$athleteId}";

        // Cache::add is atomic, so N concurrent viewers produce one fetch.
        if (! Cache::add($key, true, self::FRESH_FOR)) {
            return false;
        }

        $body = $this->espn->web("athletes/{$athleteId}/gamelog", ttl: 0);

        if ($body === null || empty($body['seasonTypes'])) {
            return false;
        }

        $names = $body['names'] ?? [];

        if ($names === []) {
            return false;
        }

        $written = 0;

        foreach ($body['seasonTypes'] as $seasonType) {
            foreach ($seasonType['categories'] ?? [] as $category) {
                $categoryName = $category['type'] ?? $category['displayName'] ?? 'general';

                foreach ($category['events'] ?? [] as $event) {
                    $written += $this->storeEvent($athleteId, $event, $names, $categoryName);
                }
            }
        }

        return $written > 0;
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
        $body = $this->espn->core("athletes/{$athleteId}/statisticslog", ttl: config('espn.cache.reference'));

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
                $categories = $this->espn->ref($statistic['statistics']['$ref'] ?? '', ttl: config('espn.cache.reference'));

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
