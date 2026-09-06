<?php

namespace App\Support;

/**
 * WHERE ONE READER STANDS in a field of them — the same arithmetic on My
 * Picks and in the clubhouse, because a place worked out twice is a place
 * that will eventually be said two ways.
 *
 * COMPETITION RANK, never a row number. `weekStandings` sorts and then
 * numbers 1..N, so two members on 14 points come out 2 and 3 with the order
 * between them decided by whatever the sort did. That is honest as a row
 * number and a lie as a claim: telling somebody they are 3rd when nobody is
 * ahead of the person above them is the confidently-wrong kind of wrong. So
 * a place is 1 plus however many are STRICTLY ahead, and a shared place
 * says out loud that it is shared.
 *
 * NULL IS NO PLACE and callers SKIP it, never substituting one:
 *
 *   - an empty field has nobody to place;
 *   - a field of one is not a standing, it is a person, and "1st of 1" is
 *     a trophy for turning up;
 *   - a board where nothing has been scored is not a ten-way tie for
 *     first, it is a week that has not started — that gate belongs to the
 *     caller, which knows whether a game has kicked.
 */
class Placing
{
    /**
     * The reader's place among everyone in the field.
     *
     * `$field` must CONTAIN the reader's own points — it is the standings
     * column, not the other people in it — or the field size understates by
     * one and a leader reads as tied with nobody.
     *
     * @param  iterable<int>  $field  every entrant's points, the reader's included
     * @return array{place: int, field: int, tied: bool}|null
     */
    public static function of(int $points, iterable $field): ?array
    {
        $all = collect($field)->map(fn ($their): int => (int) $their)->values();

        if ($all->count() < 2) {
            return null;
        }

        return [
            'place' => 1 + $all->filter(fn (int $their): bool => $their > $points)->count(),
            'field' => $all->count(),
            // The reader is in the field, so their own row is one of these —
            // a shared place needs a SECOND.
            'tied' => $all->filter(fn (int $their): bool => $their === $points)->count() > 1,
        ];
    }

    /**
     * "2nd of 10", or "T-2nd of 10" where the place is shared.
     *
     * Plain in all three registers, deliberately — the reading RankLadder's
     * rung names already take (.ai/rules/support-support.md): a place is a
     * label you hold up against somebody else's, so it has to say the same
     * word to both of you.
     *
     * @param  array{place: int, field: int, tied: bool}  $placing
     */
    public static function label(array $placing): string
    {
        return self::short($placing).' of '.$placing['field'];
    }

    /**
     * The place alone — for a column too narrow to carry the field, beside
     * a figure that already says how many are playing.
     *
     * @param  array{place: int, field: int, tied: bool}  $placing
     */
    public static function short(array $placing): string
    {
        return ($placing['tied'] ? 'T-' : '').Ordinal::of($placing['place']);
    }
}
