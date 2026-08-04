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

            /*
             * Weeks for every type that has them. Preseason and postseason
             * each have exactly one, and they are not decorative: the AP and
             * Coaches preseason poll hangs off type 1 week 1, and the final
             * rankings off type 3 week 1. Without them those two polls cannot
             * be stored at all.
             */
            $this->syncWeeks($season, $year, (int) $type['type']);

            $seasons[(int) $type['type']] = $season;
        }

        return $seasons;
    }

    /**
     * All four ESPN season types, so the calendar can read real date ranges
     * rather than infer them.
     *
     * The labels are misleading and worth stating plainly, because they are the
     * opposite of what the names suggest. Verified live for 2025:
     *
     *   1 Preseason      2025-02-01 -> 2025-08-23   (six months)
     *   2 Regular Season 2025-08-23 -> 2025-12-13
     *   3 Postseason     2025-12-13 -> 2026-01-21
     *   4 Off Season     2026-01-21 -> 2026-02-01   (eleven days)
     *
     * So ESPN's "Preseason" is the whole span most people would call the
     * offseason, and its "Off Season" is only the short bridge between the
     * playoff ending and the next cycle starting. We store both verbatim and
     * translate to human phases in CfbCalendar.
     */
    private function types(int $year): iterable
    {
        foreach ([Season::PRESEASON, Season::REGULAR, Season::POSTSEASON, Season::OFFSEASON] as $type) {
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
