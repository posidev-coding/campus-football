<?php

namespace App\Support;

use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * How a settled week finished, from one reader's point of view.
 *
 * Read from the SETTLED row rather than recomputed: settlement's whole
 * point is that a settled slate is immutable history, so `final_points`,
 * `won` and `beat_bear` are the authoritative answers and re-deriving them
 * here would be a second tiebreak implementation to drift out of agreement
 * with the first.
 *
 * The field is ranked ONCE for everybody, the way the history screen's
 * "3rd of 9" is — never once per recipient.
 */
class SlateResults
{
    /**
     * Everyone who should hear about this week: the entrants, and the
     * members who let it go by.
     *
     * The "missed" half roots in MEMBERSHIPS for the same reason the
     * reminder does — a member who never picked has no entry row — with one
     * extra gate: they must have been in the group BEFORE the card went up.
     * Telling somebody who joined on Sunday that they missed a week they
     * could not have played is a worse first impression than silence.
     *
     * @return Collection<int, array{user_id: int, entered: bool}>
     */
    public static function audience(Slate $slate): Collection
    {
        $entrants = $slate->entries->pluck('user_id');

        $missed = GroupMember::query()
            ->where('group_id', $slate->contest->group_id)
            ->whereNotIn('user_id', $entrants->all())
            ->when(
                $slate->published_at !== null,
                fn ($query) => $query->where('group_members.created_at', '<', $slate->published_at),
            )
            ->pluck('user_id');

        return $entrants
            ->map(fn (int $userId) => ['user_id' => $userId, 'entered' => true])
            ->concat($missed->map(fn (int $userId) => ['user_id' => $userId, 'entered' => false]))
            ->values();
    }

    /**
     * One reader's week: where they finished, who was next to them, and
     * what the Bear made of it. Null when there is nothing true to say.
     *
     * @return array<string, mixed>|null
     */
    public static function forUser(Slate $slate, User $user): ?array
    {
        $group = $slate->contest->group;

        $base = [
            'group' => $group->name,
            'week' => Cadence::displayWeekLabel($slate->week, $slate->saturday),
            'exhibition' => $slate->exhibition,
            'url' => $group->isRoom()
                ? route('pickem.room', $group)
                : route('pickem.group', $group),
        ];

        $ranked = self::ranked($slate);
        $winners = $ranked->where('won', true);

        $mine = $ranked->firstWhere('user_id', $user->id);

        // Never played: they hear who won, and nothing about themselves.
        if ($mine === null) {
            return $winners->isEmpty() ? null : [
                ...$base,
                'entered' => false,
                'winner' => $winners->first()['name'],
            ];
        }

        $nemesis = self::nemesis($ranked, $mine);

        return [
            ...$base,
            'entered' => true,
            'won' => (bool) $mine['won'],
            'points' => $mine['points'],
            'place' => self::ordinal($mine['place']),
            'field' => $ranked->count(),
            // Tied winners are both paid, so the shared line names the others.
            'others' => $winners->where('user_id', '!=', $user->id)->pluck('name')->implode(', '),
            'winner' => $winners->first()['name'] ?? '',
            'rival' => $nemesis['name'] ?? '',
            'margin' => (string) ($nemesis['margin'] ?? 0),
            'beat_bear' => $mine['beat_bear'],
            'bear_margin' => (string) abs($mine['points'] - ($mine['bear_total'] ?? $mine['points'])),
        ];
    }

    /**
     * The field, best first, with a competition rank — tied totals share a
     * place, and the next one down skips accordingly.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function ranked(Slate $slate): Collection
    {
        $bearTotal = self::bearTotal($slate);

        $rows = $slate->entries
            ->sortByDesc(fn (SlateEntry $entry) => (int) $entry->final_points)
            ->values()
            ->map(fn (SlateEntry $entry) => [
                'user_id' => $entry->user_id,
                'name' => self::name($entry->user),
                'points' => (int) $entry->final_points,
                'won' => (bool) $entry->won,
                'beat_bear' => $entry->beat_bear,
                'bear_total' => $bearTotal,
            ]);

        $place = 0;
        $seen = 0;
        $last = null;

        return $rows->map(function (array $row) use (&$place, &$seen, &$last) {
            $seen++;

            if ($row['points'] !== $last) {
                $place = $seen;
                $last = $row['points'];
            }

            return [...$row, 'place' => $place];
        });
    }

    /**
     * The person one place away — above you, or below you when you won.
     *
     * Not a stored relationship, and deliberately not one: a weekly pick'em
     * rivalry IS week to week, and this is the adjacency the settled field
     * already knows. It roasts the RESULT, never the person, which is what
     * keeps it inside the age rating.
     *
     * @param  Collection<int, array<string, mixed>>  $ranked
     * @param  array<string, mixed>  $mine
     * @return array<string, mixed>|null
     */
    private static function nemesis(Collection $ranked, array $mine): ?array
    {
        $index = $ranked->search(fn (array $row) => $row['user_id'] === $mine['user_id']);

        // The winner looks down; everybody else looks up.
        $neighbor = $mine['won']
            ? $ranked->get($index + 1)
            : $ranked->get($index - 1);

        if ($neighbor === null || $neighbor['user_id'] === $mine['user_id']) {
            return null;
        }

        return [
            'name' => $neighbor['name'],
            'margin' => abs($neighbor['points'] - $mine['points']),
        ];
    }

    /**
     * The Bear's total, reconstructed from the verdict already stamped on
     * the entries — no regrade, no engine, no extra query. Null on every
     * slate that fielded no Bear.
     */
    private static function bearTotal(Slate $slate): ?int
    {
        if ($slate->entries->every(fn (SlateEntry $entry) => $entry->beat_bear === null)) {
            return null;
        }

        $beat = $slate->entries->where('beat_bear', true)->min('final_points');
        $lost = $slate->entries->where('beat_bear', false)->max('final_points');

        // Strictly greater is the rule, so his total sits between the worst
        // score that beat him and the best that did not.
        return $beat !== null ? (int) $beat - 1 : (int) $lost;
    }

    /** The handle is how people know each other here; the name is the fallback. */
    private static function name(?User $user): string
    {
        if ($user === null) {
            return 'somebody';
        }

        return filled($user->handle) ? '@'.$user->handle : $user->first_name;
    }

    private static function ordinal(int $place): string
    {
        $suffix = match (true) {
            in_array($place % 100, [11, 12, 13], true) => 'th',
            $place % 10 === 1 => 'st',
            $place % 10 === 2 => 'nd',
            $place % 10 === 3 => 'rd',
            default => 'th',
        };

        return $place.$suffix;
    }
}
