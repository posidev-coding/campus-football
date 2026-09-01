<?php

namespace App\Actions;

use App\Models\User;
use App\Models\WalletEntry;
use App\Support\Cadence;
use App\Support\RankLadder;
use Illuminate\Support\Str;

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
 *
 * THE TALLBOY SUPPLY lives here as CONSTANTS rather than a table, the same
 * property RankLadder was built for: the economy is going to be wrong on
 * first contact with real users, and a rebalance should be a deploy rather
 * than a migration and a backfill.
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
     * THE RUNG-UPS. One payout per RankLadder promotion, keyed by the rung,
     * so the ladder is worth climbing rather than being a label that changes.
     * Walk-On is where everybody starts and pays nothing.
     *
     * Deliberately generous at the top and safe anyway: a rung can push a
     * balance past the cooler's six, and at six the weekly top-off stops
     * paying entirely — the ceiling absorbs a big rung without inflating the
     * economy, which is why there is no second dial to tune here.
     *
     * @var array<string, int>
     */
    public const RUNG_CREDITS = [
        'Redshirt' => 2,
        'Rotation' => 3,
        'Starter' => 4,
        'Captain' => 5,
        'All-American' => 6,
        'Legend' => 8,
    ];

    public const REASON_RUNG_UP = 'rung-up';

    /** THE MILESTONES. Keyed once ever, except the perfect week. */
    public const REASON_FIRST_SLATE = 'first-slate';

    public const FIRST_SLATE_CREDITS = 1;

    public const REASON_WEEKS_ENTERED = 'weeks-entered';

    /** Saturdays played => credits. Once ever, at the week that reaches it. */
    public const WEEKS_ENTERED_CREDITS = [5 => 2, 10 => 3];

    public const REASON_PERFECT_WEEK = 'perfect-week';

    public const PERFECT_WEEK_CREDITS = 3;

    public const REASON_FIRST_ROOM_WIN = 'first-room-win';

    public const FIRST_ROOM_WIN_CREDITS = 2;

    /**
     * THE COOLER. The weekly top-off is graduated on the balance you are
     * holding, so one rule is both a floor for the thirsty and a ceiling on
     * hoarding: empty gets restocked, half-full gets topped off, full gets
     * nothing. Six is three weeks of maximum spend — enough to bank a
     * rivalry-week splurge, low enough that sitting on credits stops paying.
     *
     * A flat weekly grant was rejected: over a season it equals maximum
     * demand on its own, which turns the balance into a number that only
     * goes up — the same failure this economy exists to fix, pointed the
     * other way.
     */
    public const REASON_TOPOFF = 'topoff';

    /**
     * THE MARQUEE ENTRY, the first sink. A spend is a NEGATIVE row with no
     * key — repeatable, because a seat is bought every time one is taken —
     * and a refund would be a new positive row, never an edit, the way a
     * bank does it.
     */
    public const REASON_ROOM_ENTRY = 'room-entry';

    public const COOLER_CAPACITY = 6;

    public const COOLER_EMPTY_AT = 2;

    public const TOPOFF_EMPTY_CREDITS = 2;

    public const TOPOFF_ROOM_CREDITS = 1;

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
        $written = $key === null
            ? WalletEntry::query()->insert($entry)
            : WalletEntry::query()->insertOrIgnore($entry) > 0;

        if ($written) {
            $user->forgetWalletTotals();
        }

        return $written;
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

    /**
     * The same shape one period up: a repeatable earn capped per WEEK.
     *
     * The week is the football week — `Cadence::currentSaturday()`, the
     * Saturday this pick'em week is ON — and never an ISO week number. An
     * ESPN week can hold two Saturdays, so a week number is not an identity
     * around here; the Saturday is, and every write path already keys on it.
     *
     * @return bool whether this call actually paid
     */
    public function weekly(User $user, int $xp, int $credits, string $reason, int $cap = 1, ?string $slot = null): bool
    {
        if (! $user->hasVerifiedEmail()) {
            return false;
        }

        $prefix = $reason.':'.Cadence::currentSaturday()->toDateString().':';

        $spent = WalletEntry::query()
            ->where('user_id', $user->id)
            ->where('key', 'like', $prefix.'%')
            ->count();

        if ($spent >= $cap) {
            return false;
        }

        return $this->handle($user, $xp, $credits, $reason, $prefix.($slot ?? $spent + 1));
    }

    /**
     * Restock the cooler for this football week, and say what it paid.
     *
     * THE KEY IS CHECKED BEFORE THE BALANCE IS READ, and the order is
     * load-bearing rather than an optimization: the amount is computed FROM
     * the balance, so a second fire that reads first would compute a
     * different number and then discover it has nothing to write — a grant
     * whose value depends on when it lost a race. Ask the key first and the
     * second fire never computes anything at all.
     *
     * A FULL COOLER STILL SPENDS THE KEY. The row is written at zero, which
     * is the honest ledger statement ("topped off this week, nothing owed")
     * and, more importantly, is what stops the top-off from being farmed: a
     * reader holding six who spends two and comes back would otherwise be
     * restocked again the same week, and again after that.
     *
     * @return int|null credits granted, or null when this week is already
     *                  claimed (or the account cannot earn at all)
     */
    public function topOff(User $user): ?int
    {
        if (! $user->hasVerifiedEmail()) {
            return null;
        }

        $key = self::REASON_TOPOFF.':'.Cadence::currentSaturday()->toDateString();

        if (WalletEntry::query()->where('user_id', $user->id)->where('key', $key)->exists()) {
            return null;
        }

        $credits = self::topOffFor($this->creditBalance($user));

        return $this->handle($user, 0, $credits, self::REASON_TOPOFF, $key) ? $credits : null;
    }

    /**
     * What the cooler owes a wallet holding this much. Public because the
     * Picks explainer states the three tiers and must read them from here
     * rather than restating numbers that will be rebalanced.
     */
    public static function topOffFor(int $balance): int
    {
        return match (true) {
            $balance <= self::COOLER_EMPTY_AT => self::TOPOFF_EMPTY_CREDITS,
            $balance < self::COOLER_CAPACITY => self::TOPOFF_ROOM_CREDITS,
            default => 0,
        };
    }

    /**
     * Pay every RankLadder rung this wallet has already reached.
     *
     * Swept rather than fired at the moment of promotion, and cumulative
     * rather than "the rung you just crossed": the rung is a pure function
     * of the XP total, so a sweep is exactly as complete as an eager hook
     * and costs nothing in the settlement job that would otherwise run one
     * extra SUM per entrant. Somebody who climbed to Rotation before ever
     * opening Picks collects Redshirt AND Rotation on the visit — the keys
     * make that once ever, whichever order it happens in.
     *
     * @return array<string, int> rung name => credits actually paid
     */
    public function rungUps(User $user): array
    {
        if (! $user->hasVerifiedEmail()) {
            return [];
        }

        $xp = $user->walletTotals()['xp'];
        $paid = [];

        foreach (RankLadder::RUNGS as $rung => $threshold) {
            // Ordered lowest first, so the first rung out of reach ends it.
            if ($xp < $threshold) {
                break;
            }

            $credits = self::RUNG_CREDITS[$rung] ?? 0;

            if ($credits > 0 && $this->handle($user, 0, $credits, self::REASON_RUNG_UP, 'rung:'.Str::slug($rung))) {
                $paid[$rung] = $credits;
            }
        }

        return $paid;
    }

    /**
     * The wallet's credit balance, read fresh.
     *
     * Never User::walletTotals() here: that memo is per instance and exists
     * to make two chip renders one query, so a grant written earlier in the
     * same request would be invisible to the number this decides.
     */
    private function creditBalance(User $user): int
    {
        return (int) WalletEntry::query()
            ->where('user_id', $user->id)
            ->sum('credits');
    }
}
