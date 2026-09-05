<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * WHO YOU MAY INVITE DIRECTLY — the people you have already played with.
 *
 * The audience is co-membership and nothing wider: somebody who shares a
 * private group or a public room with you. There is deliberately no global
 * user search behind this. A directory of every account, searchable by
 * anybody, is a different product decision than "invite the people you play
 * with", and it is the one that cannot be taken back.
 *
 * THE PRIVACY BOUNDARY, and why it is stricter than the clubhouse's.
 *
 * `group.blade.php`'s `showsRealNames()` prints real names inside a private
 * group and handles inside a public room, because the seam is the kind of
 * room. This list has no such seam to read: it MIXES acquaintances from
 * private groups and public rooms into one column, and the reader cannot
 * tell by looking which row came from which. Printing a name here would
 * publish, to a private group's Invite tab, an identity that the public room
 * it came from is not allowed to publish. So the picker is handles only.
 *
 * That is enforced structurally rather than by discipline in a template:
 * the query SELECTS ONLY `id` and `handle`, and this class hands back plain
 * arrays, so there is no User model in the render path with a `name` on it
 * to reach for. A user who has never claimed a handle is therefore not
 * invitable at all — they cannot be named without naming them for real, and
 * the safe direction is silence. They are still reachable by the link.
 */
class Invitables
{
    /** Enough to choose from on a phone without becoming a directory. */
    public const LIMIT = 8;

    /**
     * The people :actor may ask into :group, handles only.
     *
     * `pending` is a send that already happened — the row stays in the list
     * wearing "Invited" rather than vanishing, because a control that
     * disappears when you press it reads as a failure.
     *
     * `shared` is the group or room you both sit in, so a handle is not a
     * stranger. Null is a real answer and the line is simply skipped.
     *
     * @return list<array{id: int, handle: string, shared: string|null, pending: bool}>
     */
    public static function for(User $actor, Group $group, string $query = ''): array
    {
        $mine = GroupMember::query()
            ->where('user_id', $actor->id)
            ->pluck('group_id');

        if ($mine->isEmpty()) {
            return [];
        }

        $term = trim(mb_strtolower($query));

        $candidates = User::query()
            ->select(['users.id', 'users.handle'])
            ->whereNotNull('users.handle')
            ->whereKeyNot($actor->id)
            ->whereIn('users.id', fn ($sub) => $sub
                ->select('user_id')
                ->from('group_members')
                ->whereIn('group_id', $mine))
            ->whereNotIn('users.id', fn ($sub) => $sub
                ->select('user_id')
                ->from('group_members')
                ->where('group_id', $group->id))
            ->when($term !== '', fn ($q) => $q->where('users.handle', 'like', $term.'%'))
            ->orderBy('users.handle')
            ->limit(self::LIMIT)
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $ids = $candidates->pluck('id');

        /*
         * Two more queries for the whole page, never one per row. A lookup
         * inside the map below is the missing-eager-load class of bug, and
         * `.ai/rules/tests.md` is explicit that no feature test can catch
         * it — InviteTest sweeps this file's source instead.
         *
         * Nothing here is memoized in a static: `TeamGlance` had to be
         * flushed in `tests/Pest.php` for exactly that reason, and a cache
         * keyed on ids that RefreshDatabase hands out again is a stale
         * "Invited" badge in the next test.
         */
        $shared = [];

        $rows = DB::table('group_members')
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->whereIn('group_members.group_id', $mine)
            ->whereIn('group_members.user_id', $ids)
            ->orderBy('group_members.group_id')
            ->get(['group_members.user_id', 'groups.name']);

        foreach ($rows as $row) {
            $shared[(int) $row->user_id] ??= (string) $row->name;
        }

        $pending = GroupInvite::query()
            ->where('group_id', $group->id)
            ->whereIn('invitee_id', $ids)
            ->pluck('invitee_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $candidates
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'handle' => (string) $user->handle,
                'shared' => $shared[(int) $user->id] ?? null,
                'pending' => in_array((int) $user->id, $pending, true),
            ])
            ->all();
    }

    /**
     * Whether :actor is allowed to ask :invitee anywhere at all — the same
     * co-membership rule the list is built from, asked of one person.
     *
     * This exists so InviteUserToGroup can enforce the boundary without
     * rebuilding the list: a screen's rendered options are presentation,
     * and the Action is the gate.
     */
    public static function allows(User $actor, User $invitee): bool
    {
        if ($invitee->handle === null || $invitee->id === $actor->id) {
            return false;
        }

        return GroupMember::query()
            ->where('user_id', $invitee->id)
            ->whereIn('group_id', GroupMember::query()
                ->select('group_id')
                ->where('user_id', $actor->id))
            ->exists();
    }
}
