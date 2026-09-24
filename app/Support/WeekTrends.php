<?php

namespace App\Support;

use App\Models\Contest;
use App\Models\Slate;
use App\Models\SlateEntry;

/**
 * A member's recent weeks in one contest: where they placed, and whether that
 * was the top half of the room.
 *
 * TOP HALF, NOT THE WEEKLY WIN. `slate_entries.won` crowns one person a slate,
 * so a strip built on it reads L-L-L-L-L for nearly everyone in a group of
 * fifteen. That roasts the person, not the pick. `beat_bear` would be fairer
 * but only a Woodshed slate fields a Bear, so it is null for every Classic and
 * Tiered week. Top half is answerable in every mode from `final_points`, and
 * it scales with the room (CFB-30).
 *
 * The place is {@see Placing::of()}, competition rank, so a shared place is
 * shared here exactly as the week band says it. A week is top half when
 * `place * 2 <= field`: first of two, second of four, second of five.
 *
 * NULL IS NO PLACE: a week with a field under two has no standing, and it is
 * left out of the run rather than shown as a loss.
 *
 * THE SEASON LEDGER'S RULE: a practice week grades and crowns its own winner
 * but never moves the season, so `slates.exhibition` is asked here by the
 * same name as the clubhouse's three ledger joins.
 */
class WeekTrends
{
    /** How many weeks the strip shows. */
    public const WEEKS = 5;

    /**
     * Every member's last {@see WEEKS} placed weeks, oldest first, so the row
     * reads left to right toward now.
     *
     * ONE query for the whole contest, never one per member: a settled
     * season is a few hundred entries at most, and every place needs the
     * whole field of its week anyway.
     *
     * @return array<int, list<array{saturday: string, place: int, field: int, tied: bool, top_half: bool}>> user id => weeks
     */
    public static function for(Contest $contest, int $weeks = self::WEEKS): array
    {
        $entries = SlateEntry::query()
            ->join('slates', 'slates.id', '=', 'slate_entries.slate_id')
            ->where('slates.contest_id', $contest->id)
            ->where('slates.status', Slate::SETTLED)
            ->where('slates.exhibition', false)
            ->whereNotNull('slate_entries.final_points')
            ->orderBy('slates.saturday')
            ->get(['slate_entries.user_id', 'slate_entries.final_points', 'slates.id as week', 'slates.saturday']);

        $trends = [];

        foreach ($entries->groupBy('week') as $field) {
            $points = $field->pluck('final_points');

            foreach ($field as $entry) {
                $placing = Placing::of((int) $entry->final_points, $points);

                if ($placing === null) {
                    continue;
                }

                $trends[$entry->user_id][] = [
                    'saturday' => substr((string) $entry->saturday, 0, 10),
                    ...$placing,
                    'top_half' => $placing['field'] >= $placing['place'] * 2,
                ];
            }
        }

        return array_map(fn (array $run): array => array_slice($run, -$weeks), $trends);
    }
}
