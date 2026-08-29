<?php

namespace App\Support;

use App\Models\Contest;
use App\Models\Week;
use App\Services\Contests\SuggestSlate;
use Carbon\CarbonInterface;

/**
 * Whether a GROUP can field a slate on the Saturday in front of it — the
 * private half of `LobbyCatalog::resolve()`.
 *
 * A house room is provisioned: resolve() asks this same question before
 * spawning one, so a Saturday that cannot seat the shape simply has no
 * room and the lobby dashes the row. A group already exists and cannot be
 * un-spawned, so the question its clubhouse asks is "can my commissioner
 * build THIS week" — and the answer has to name both numbers, because
 * "not yet" without them reads as a broken button.
 *
 * A group never downsizes the way a house Shotgun room does. The room is
 * one Saturday and its size is frozen at spawn to whatever exists; a
 * group's mode is a season-long promise its members chose, and quietly
 * dealing eight games one week because that is all there was would change
 * the game under them.
 *
 * COST: one query for the week's slate-eligible games plus the same
 * scoring pass the builder runs — cheap enough for one clubhouse, and
 * deliberately NOT cheap enough for a list. A screen showing many cards
 * resolves the count ONCE (`fromCount()`) and compares every row against
 * it; per row it would be a slate suggestion per row.
 */
class SlateFeasibility
{
    /**
     * @return array{ok: bool, viable: int, needed: int, next: CarbonInterface}
     */
    public static function for(Contest $contest, Week $week, CarbonInterface $saturday): array
    {
        return self::fromCount(
            app(SuggestSlate::class)->viableCount($contest, $week, $saturday),
            $contest,
            $saturday,
        );
    }

    /**
     * The same answer from a count already in hand — for a screen that
     * asked the Saturday once on behalf of several contests. Only safe
     * where the count was drawn WITHOUT a themed filter, which is every
     * private group: `slate_filter` is a flavored room's knob.
     *
     * @return array{ok: bool, viable: int, needed: int, next: CarbonInterface}
     */
    public static function fromCount(int $viable, Contest $contest, CarbonInterface $saturday): array
    {
        $needed = $contest->mode->engine($contest->settings)->slateSize();

        return [
            'ok' => $viable >= $needed,
            'viable' => $viable,
            'needed' => $needed,
            // The next Saturday on the calendar, not the next one proven
            // to be playable — the copy says "can", never "will".
            'next' => $saturday->copy()->addWeek(),
        ];
    }
}
