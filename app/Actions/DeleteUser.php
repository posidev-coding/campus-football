<?php

namespace App\Actions;

use App\Models\User;

/**
 * Delete an account, and everything that only existed because of it.
 *
 * Most of the cascade is the schema's: `picks`, `slate_entries`,
 * `group_members`, `team_follows`, `wallet_entries` and `conversation_posts`
 * all carry `user_id` foreign keys with ON DELETE CASCADE, so they go without
 * being asked.
 *
 * The two that do NOT are morphs with no foreign key at all — `notifications`
 * and `push_subscriptions` — and an orphan in either is invisible rather than
 * loud. `User::pruning()` already hand-deletes the notifications for exactly
 * this reason; this mirrors it and adds the push rows, whose orphans would
 * also inflate the `push_devices` telemetry count (that one counts the table
 * directly, so a row with no user still reads as a reachable device).
 */
class DeleteUser
{
    /**
     * @return bool false when the actor aimed at themselves, which is the one
     *              refusal — a panel with no admins left is not recoverable
     *              from inside the panel
     */
    public function handle(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        $target->notifications()->delete();
        $target->pushSubscriptions()->delete();

        $target->delete();

        return true;
    }
}
