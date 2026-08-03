<?php

namespace App\Services\Espn;

/**
 * Reads values out of an ESPN `records[]` entry.
 *
 * Two rules here exist because v3 broke on both:
 *
 * 1. Records and stats are addressed by NAME, never by array position. v3's
 *    standings view indexed `stats[0]` and `stats[1]` positionally; commit
 *    dde53b3 ("Conf standings bug") was exactly that, and it would have broken
 *    again the moment ESPN reordered the array.
 *
 * 2. Record strings are parsed tolerantly. v3 used `explode('-', $summary)`
 *    and assumed two parts, which produces garbage on a tie record like
 *    "10-2-1" and throws on anything unexpected.
 */
class RecordParser
{
    /**
     * Find one record entry by its ESPN `type`.
     *
     * Types seen on college football standings, verified live:
     * total, homerecord, awayrecord, vsconf, vsdivision, vsaprankedteams,
     * vsusarankedteams.
     */
    public static function record(array $records, string $type): ?array
    {
        foreach ($records as $record) {
            if (($record['type'] ?? null) === $type) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Pull a named stat out of a record.
     *
     * Returns null when absent rather than 0 — the caller must be able to tell
     * "ESPN says zero" apart from "ESPN did not say", because writing a default
     * on the second case is what destroyed real records in v3.
     */
    public static function stat(?array $record, string $name): int|float|null
    {
        if ($record === null) {
            return null;
        }

        foreach ($record['stats'] ?? [] as $stat) {
            if (($stat['name'] ?? null) === $name) {
                return $stat['value'] ?? null;
            }
        }

        return null;
    }

    public static function intStat(?array $record, string $name): ?int
    {
        $value = self::stat($record, $name);

        return $value === null ? null : (int) $value;
    }

    public static function floatStat(?array $record, string $name): ?float
    {
        $value = self::stat($record, $name);

        return $value === null ? null : (float) $value;
    }

    public static function displayValue(?array $record): ?string
    {
        return $record['displayValue'] ?? null;
    }

    /**
     * Parse a "W-L" or "W-L-T" display string.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    public static function parseRecordString(?string $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! preg_match('/^\s*(\d+)\s*-\s*(\d+)(?:\s*-\s*(\d+))?\s*$/', $value, $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
    }

    /**
     * ESPN references a team only by `$ref`; the id lives in the path.
     */
    public static function teamIdFromRef(string $ref): ?int
    {
        return preg_match('#/teams/(\d+)#', $ref, $m) ? (int) $m[1] : null;
    }

    public static function athleteIdFromRef(string $ref): ?int
    {
        return preg_match('#/athletes/(\d+)#', $ref, $m) ? (int) $m[1] : null;
    }
}
