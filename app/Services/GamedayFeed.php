<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The College GameDay promo feed, read narrowly and on purpose.
 *
 * ONE REQUEST A WEEK, no polling, and not through {@see Espn\EspnClient} —
 * that client owns ESPN's JSON APIs and their cost tiers, and a promo page
 * does not belong inside a rate limiter sized for live scoring.
 *
 * The payload is hand-maintained and last season's content was left in place
 * rather than removed, so almost all of it is decoration and some of it is
 * actively wrong. Verified in the live 2026-08-24 sample:
 *
 *   map.*             carryover. matchups[0] is LSU; its map block reads
 *                     "South Oval", "Norman Oklahoma", ou-map.png.
 *   homeTeamLogoAlt   says "Ohio State logo" beside lsu.png.
 *   schedule.dates    December 2025.
 *   videos.playlist   2025 Heisman/CFP.
 *   id                "Clemson-vs-LSU" one row, "Ohio State vs Texas" the next.
 *   asset paths       stamped /2025/ even for 2026 content.
 *   instagram.city    carries a typo ("Baton Rougue").
 *
 * So this reads FOUR FIELDS and nothing else, and even those only decide
 * which Saturday and which place to ask our own database about.
 */
class GamedayFeed
{
    /** Everything this class is willing to believe. */
    public const TRUSTED = ['cutoffTime', 'location', 'date', 'prefix'];

    /**
     * The decoded payload, or null when there isn't one.
     *
     * Null covers unconfigured, unreachable, non-200 and unparseable alike,
     * because the caller does the same thing with all four: fall through to
     * the model, and failing that, write `unknown`.
     *
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        $url = config('gameday.feed_url');

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        try {
            $response = Http::timeout(config('gameday.timeout'))->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * The trusted fields of every matchup the payload carries, in order.
     *
     * A row missing `cutoffTime` or `location` is dropped rather than
     * half-read: those two ARE the record, and the other two are labels.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{cutoff: CarbonImmutable, location: string, date: ?string, prefix: ?string}>
     */
    public function matchups(array $payload): array
    {
        $matchups = $payload['matchups'] ?? null;

        if (! is_array($matchups)) {
            return [];
        }

        $read = [];

        foreach ($matchups as $matchup) {
            // One malformed row must not cost the rest of the payload — the
            // same isolation every loop over an ESPN feed uses.
            if (! is_array($matchup)) {
                continue;
            }

            $cutoff = $this->cutoff($matchup['cutoffTime'] ?? null);
            $location = $matchup['location'] ?? null;

            if ($cutoff === null || ! is_string($location) || trim($location) === '') {
                continue;
            }

            $read[] = [
                'cutoff' => $cutoff,
                'location' => trim($location),
                'date' => is_string($matchup['date'] ?? null) ? trim($matchup['date']) : null,
                'prefix' => is_string($matchup['prefix'] ?? null) ? trim($matchup['prefix']) : null,
            ];
        }

        return $read;
    }

    /**
     * THE FRESHNESS GUARD, and the trap this feed sets.
     *
     * `matchups[]` is hand-maintained, so it will lag at some point — and
     * because stale rows are HIDDEN by booleans rather than removed, the most
     * recent row is always sitting there looking answerable. If no cutoff
     * falls on the Saturday being asked about, the answer is nothing.
     *
     * @param  array<string, mixed>  $payload
     * @return array{cutoff: CarbonImmutable, location: string, date: ?string, prefix: ?string}|null
     */
    public function forSaturday(array $payload, CarbonInterface|string $saturday): ?array
    {
        $target = CarbonImmutable::parse($saturday, config('cfb.timezone'))->toDateString();

        foreach ($this->matchups($payload) as $matchup) {
            if ($matchup['cutoff']->toDateString() === $target) {
                return $matchup;
            }
        }

        return null;
    }

    /**
     * A fingerprint of what we READ, not of the whole file.
     *
     * Hashing the raw payload would change on every unrelated edit to a page
     * that is edited by hand constantly. This changes when one of the four
     * trusted fields changes, which is the only change worth noticing.
     *
     * @param  array{cutoff: CarbonImmutable, location: string, date: ?string, prefix: ?string}  $matchup
     */
    public function fingerprint(array $matchup): string
    {
        return hash('sha256', json_encode([
            $matchup['cutoff']->toDateTimeString(),
            $matchup['location'],
            $matchup['date'],
            $matchup['prefix'],
        ]));
    }

    /**
     * `"2026-09-05T09:00:00"` — no zone, so it is read in ours rather than
     * silently in UTC, which would move an early-morning cutoff to the day
     * before.
     */
    private function cutoff(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, config('cfb.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
