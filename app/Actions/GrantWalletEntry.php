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

    public const VERIFICATION_CREDITS = 1;

    public const REASON_FIRST_TEAM = 'first-team';

    public const FIRST_TEAM_XP = 25;

    public const REASON_FIRST_GROUP_CREATED = 'first-group-created';

    public const FIRST_GROUP_CREATED_XP = 50;

    public const REASON_FIRST_GROUP = 'first-group';

    public const FIRST_GROUP_XP = 25;

    public const REASON_PICKEM_ENTERED = 'pickem-entered';

    public const PICKEM_ENTERED_XP = 10;

    public const REASON_PICKEM_POINTS = 'pickem-points';

    public const PICKEM_POINTS_XP_EACH = 10;

    public const REASON_PICKEM_WIN = 'pickem-win';

    public const PICKEM_WIN_XP = 100;

    public const PICKEM_WIN_CREDITS = 1;

    /** Saying something in a conversation, up to a few times a day. */
    public const REASON_TALK = 'talk';

    public const TALK_XP = 5;

    public const TALK_DAILY_CAP = 3;

    /**
     * The Film Room: reading a game's preview or its box score.
     *
     * Paid per GAME rather than per view, so re-reading the same box score
     * earns once ever — the slot is the game id, and the cap is how many
     * DIFFERENT games can pay in a day.
     */
    public const REASON_FILM_ROOM = 'film-room';

    public const FILM_ROOM_XP = 5;

    public const FILM_ROOM_DAILY_CAP = 5;

    /**
     * @return bool whether a row was actually written — false means the key
     *              was already spent, which is a no-op and not a failure
     */
    public function handle(User $user, int $xp, int $credits, string $reason, ?string $key = null): bool
    {
        $entry = [
            'user_id' => $user->id,
            'xp' => $xp,
            'credits' => $credits,
            'reason' => $reason,
            'key' => $key,
        ];

        // insertOrIgnore rather than catching the violation: the unique index
        // is the guarantee, this is just the quiet path through it. created_at
        // fills from the column's DB default on both branches.
        return $key === null
            ? WalletEntry::query()->insert($entry)
            : WalletEntry::query()->insertOrIgnore($entry) > 0;
    }

    /**
     * A repeatable earn that is capped per DAY by the shape of its keys.
     *
     * There is no throttle code here on purpose. The day is stamped into the
     * key (`talk:2026-08-20:2`), so the `(user_id, key)` unique index is the
     * cap itself: at most `$cap` distinct keys exist for a user on a day, and
     * a double-fired grant re-uses a spent one and inserts nothing.
     *
     * `$slot` names WHAT is being paid for when the thing is identifiable — a
     * game id for the Film Room, so reading the same box score twice pays
     * once and burns one of the day's five. Without a slot the next free
     * sequence number is used, which is right for a post: each one is its own
     * event and only the count matters.
     *
     * The day is the FOOTBALL day (Eastern), the same wall clock Cadence
     * resolves against — a post at 01:00 UTC Sunday belongs to Saturday
     * night, and a UTC day boundary would hand somebody a second allowance
     * in the middle of a game.
     *
     * @return bool whether this call actually paid
     */
    public function daily(User $user, int $xp, int $credits, string $reason, int $cap, ?string $slot = null): bool
    {
        // Never earn on an unverified account. Every capped earn is a
        // participation reward, and participation is what verification gates
        // — the one seeded exception (FIRST_TEAM) does not come through here.
        if (! $user->hasVerifiedEmail()) {
            return false;
        }

        $prefix = $reason.':'.now(config('cfb.timezone'))->toDateString().':';

        $spent = WalletEntry::query()
            ->where('user_id', $user->id)
            ->where('key', 'like', $prefix.'%')
            ->count();

        if ($spent >= $cap) {
            return false;
        }

        /*
         * Two simultaneous posts can both read the same `$spent` and build
         * the same sequence key; one inserts and the other no-ops, so a race
         * UNDER-pays by one rather than paying twice. That is the safe
         * direction for an anti-farming cap, and the reason the sequence is
         * derived rather than counted up in the row itself.
         */
        return $this->handle($user, $xp, $credits, $reason, $prefix.($slot ?? $spent + 1));
    }
}
