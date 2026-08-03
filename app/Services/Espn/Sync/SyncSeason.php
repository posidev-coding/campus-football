<?php

namespace App\Services\Espn\Sync;

use App\Models\Season;
use App\Models\Week;
use App\Services\Espn\EspnClient;
use Carbon\CarbonImmutable;

/**
 * Seasons and their weeks.
 *
 * v3 never scheduled its calendar sync, but its games feed resolved a game's
 * week by scanning date ranges in that table — so a stale calendar silently
 * broke game ingestion for the whole season. This runs first in every backfill
 * and nightly thereafter.
 */
class SyncSeason
{
    public function __construct(private EspnClient $espn) {}

    /**
     * @return array<int, Season> keyed by ESPN season type
     */
    public function handle(int $year): array
    {
        $body = $this->espn->core("seasons/{$year}");

        if ($body === null) {
            return [];
        }

        $seasons = [];

        foreach ($this->types($year) as $type) {
            $season = Season::updateOrCreate(
                ['year' => $year, 'type' => (int) $type['type']],
                [
                    'name' => $type['name'] ?? "Season {$year}",
                    'start_date' => $this->date($type['startDate'] ?? null),
                    'end_date' => $this->date($type['endDate'] ?? null),
                ]
            );

            $this->syncWeeks($season, $year, (int) $type['type']);

            $seasons[(int) $type['type']] = $season;
        }

        return $seasons;
    }

    /**
     * Only the types that carry games. Preseason and offseason exist in the
     * API but have no schedule worth ingesting.
     */
    private function types(int $year): iterable
    {
        foreach ([Season::REGULAR, Season::POSTSEASON] as $type) {
            $body = $this->espn->core("seasons/{$year}/types/{$type}");

            if ($body !== null && isset($body['type'])) {
                yield $body;
            }
        }
    }

    private function syncWeeks(Season $season, int $year, int $type): void
    {
        foreach ($this->espn->paginate("seasons/{$year}/types/{$type}/weeks") as $week) {
            if ($week === null || ! isset($week['number'])) {
                continue;
            }

            Week::updateOrCreate(
                ['season_id' => $season->id, 'number' => (int) $week['number']],
                [
                    'name' => $week['text'] ?? "Week {$week['number']}",
                    'start_date' => $this->date($week['startDate'] ?? null),
                    'end_date' => $this->date($week['endDate'] ?? null),
                ]
            );
        }
    }

    private function date(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value) : null;
    }
}
