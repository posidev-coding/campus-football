<?php

namespace App\Support;

/**
 * The rank a wallet's XP total has earned.
 *
 * A pure computation over one integer, deliberately NOT a table: rebalancing
 * the ladder is a deploy, not a migration and a backfill, and there is no
 * stored rank that can drift out of step with the ledger it was derived
 * from. `User::walletTotals()` is the only input.
 *
 * The rungs are a DEPTH CHART, which is why the bottom one is unflattering:
 * everybody starts as a walk-on, and the ladder reads as playing time rather
 * than as a participation trophy. Names are product surface — they belong to
 * Voice's register only in the copy AROUND them; the rung itself is a label
 * a reader scans for, so it stays the same word for everybody.
 *
 * Thresholds are cumulative XP, each roughly double the last: a verified
 * account starts at 125 XP (100 verification + the 25-XP first-team seed),
 * a full pick'em week pays about 250, so REDSHIRT lands in week one and
 * LEGEND is a genuine season-long climb rather than a month of Saturdays.
 */
class RankLadder
{
    /**
     * Rung name => the XP at which it is reached, lowest first.
     *
     * @var array<string, int>
     */
    public const RUNGS = [
        'Walk-On' => 0,
        'Redshirt' => 250,
        'Rotation' => 750,
        'Starter' => 1750,
        'Captain' => 3500,
        'All-American' => 7000,
        'Legend' => 15000,
    ];

    /**
     * The rung this much XP has reached, and how far the next one is.
     *
     * `next` and `remaining` are NULL at the top of the ladder — null means
     * "there is no next rung", and a caller must skip the progress line
     * rather than substitute a zero that would render as a full bar.
     *
     * @return array{name: string, floor: int, next: string|null, at: int|null, remaining: int|null, progress: float}
     */
    public static function for(int $xp): array
    {
        $name = array_key_first(self::RUNGS);
        $floor = 0;
        $next = null;
        $at = null;

        foreach (self::RUNGS as $rung => $threshold) {
            if ($xp >= $threshold) {
                $name = $rung;
                $floor = $threshold;

                continue;
            }

            $next = $rung;
            $at = $threshold;

            break;
        }

        return [
            'name' => $name,
            'floor' => $floor,
            'next' => $next,
            'at' => $at,
            'remaining' => $at === null ? null : $at - $xp,
            // A share of the CURRENT rung's span, so the bar restarts at each
            // promotion instead of creeping toward a distant Legend forever.
            'progress' => $at === null ? 1.0 : ($xp - $floor) / ($at - $floor),
        ];
    }

    /** Just the label, for the chip that has room for nothing else. */
    public static function name(int $xp): string
    {
        return self::for($xp)['name'];
    }
}
