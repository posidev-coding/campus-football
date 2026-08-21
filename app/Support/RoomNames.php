<?php

namespace App\Support;

use App\Enums\ContestMode;
use Illuminate\Support\Collection;

/**
 * Names for the lobby's rooms — a place gets a NAME, not a serial
 * number, and the name never carries a date or the word "Open": the card's
 * mode chip and the lobby's week context say the boring facts.
 *
 * Standard rooms draw from a per-mode pool matching the mode's character
 * (play calls, option schemes, backyard lumber); specialty rooms carry
 * their flavor's marquee. When a lobby exhausts a pool — or a marquee
 * room fills — successors take Roman numerals: "Hail Mary II". Pool order
 * is pick order, so an empty lobby's first spawn is deterministic and a
 * test can name it.
 *
 * Names are DATA, not Voice: stored on groups.name (VARCHAR 40, tested
 * with numeral headroom), identical in every register, PG-safe, and never
 * a school — a hardcoded school is somebody's rival.
 */
class RoomNames
{
    /** @var array<string, list<string>> keyed by ContestMode backing value */
    public const POOLS = [
        'classic' => [
            'Hail Mary', 'Flea Flicker', 'Four Verts', 'Play Action',
            'Double Reverse', 'Screen Pass', 'Jet Sweep', 'The Audible',
            'Wheel Route', 'Draw Play',
        ],
        'tiered' => [
            'Wishbone', 'Flexbone', 'Veer', 'Midline',
            'Counter Dive', 'Power Read', 'Zone Read', 'Speed Option',
            'Load Option', 'Belly Series',
        ],
        'woodshed' => [
            'The Splinter', 'The Toolshed', 'The Woodpile', 'The Sawhorse',
            'The Crosscut', 'The Two-by-Four', 'The Sawdust Pit', 'The Hatchet',
            'The Whittler', 'The Lumber Yard',
        ],
    ];

    /**
     * The next standard-room name for this mode: first unused pool name in
     * the lobby, then the pool again in Roman rounds.
     *
     * @param  Collection<int, string>  $taken  names already in this lobby
     */
    public static function next(ContestMode $mode, Collection $taken): string
    {
        $pool = self::POOLS[$mode->value];

        foreach ($pool as $name) {
            if (! $taken->contains($name)) {
                return $name;
            }
        }

        for ($round = 2; ; $round++) {
            foreach ($pool as $name) {
                $candidate = $name.' '.self::roman($round);

                if (! $taken->contains($candidate)) {
                    return $candidate;
                }
            }
        }
    }

    /**
     * A specialty room's name: the marquee itself, then Roman successors
     * as rooms fill — "Ranked Action", "Ranked Action II".
     *
     * @param  Collection<int, string>  $taken
     */
    public static function successor(string $marquee, Collection $taken): string
    {
        if (! $taken->contains($marquee)) {
            return $marquee;
        }

        for ($round = 2; ; $round++) {
            $candidate = $marquee.' '.self::roman($round);

            if (! $taken->contains($candidate)) {
                return $candidate;
            }
        }
    }

    private static function roman(int $number): string
    {
        $map = [
            1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD',
            100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL',
            10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I',
        ];

        $out = '';

        foreach ($map as $value => $glyph) {
            while ($number >= $value) {
                $out .= $glyph;
                $number -= $value;
            }
        }

        return $out;
    }
}
