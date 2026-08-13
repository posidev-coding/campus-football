<?php

namespace App\Actions;

use App\Models\User;
use App\Models\WalletEntry;

/**
 * Write one line into the wallet ledger.
 *
 * The single doorway for every earn and spend, so the idempotency rule cannot
 * be forgotten by a new caller: a ONE-TIME grant passes `$key` and relies on
 * the `(user_id, key)` unique index — a double-fired event inserts zero rows
 * instead of paying twice. A REPEATABLE entry (a weekly win, a contest entry
 * spend) passes no key and always inserts.
 *
 * Earning is gated on a verified email everywhere except the one documented
 * seed: FIRST_TEAM pays 25 XP during the onboarding moment, before
 * verification, to put a number on the scoreboard worth protecting.
 */
class GrantWalletEntry
{
    public const REASON_EMAIL_VERIFIED = 'email-verified';

    public const VERIFICATION_XP = 100;

    public const VERIFICATION_LATTES = 1;

    public const REASON_FIRST_TEAM = 'first-team';

    public const FIRST_TEAM_XP = 25;

    public function handle(User $user, int $xp, int $lattes, string $reason, ?string $key = null): void
    {
        $entry = [
            'user_id' => $user->id,
            'xp' => $xp,
            'lattes' => $lattes,
            'reason' => $reason,
            'key' => $key,
        ];

        // insertOrIgnore rather than catching the violation: the unique index
        // is the guarantee, this is just the quiet path through it. created_at
        // fills from the column's DB default on both branches.
        $key === null
            ? WalletEntry::query()->insert($entry)
            : WalletEntry::query()->insertOrIgnore($entry);
    }
}
