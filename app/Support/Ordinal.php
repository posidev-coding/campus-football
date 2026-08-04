<?php

namespace App\Support;

/**
 * National ranks read as ordinals throughout the app — "81st in average gain",
 * "3rd in scoring defense".
 *
 * ESPN ships a `rankDisplayValue` alongside the numeric rank, but it is not
 * always present and we also rank things ourselves, so the formatting lives
 * here rather than depending on the feed for a string we can derive.
 */
class Ordinal
{
    public static function of(?int $number): ?string
    {
        if ($number === null) {
            return null;
        }

        return $number.self::suffix($number);
    }

    /**
     * The 11/12/13 exception is the whole reason this is not a one-line match:
     * they take "th" despite ending in 1, 2 and 3.
     */
    public static function suffix(int $number): string
    {
        $abs = abs($number);

        if (($abs % 100) >= 11 && ($abs % 100) <= 13) {
            return 'th';
        }

        return match ($abs % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}
