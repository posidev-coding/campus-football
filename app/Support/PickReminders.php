<?php

namespace App\Support;

use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who still owes picks, and on what.
 *
 * One home for the question, because it is asked TWICE and the two answers
 * must agree: the sweep asks it to decide who to write to, and the job asks
 * it again at run time to decide whether the message is still true. A
 * reminder released past kickoff by the mail budget is worse than no
 * reminder at all, so the second ask is what turns "sends a wrong message"
 * into "silently drops".
 *
 * THE AUDIENCE ROOTS IN MEMBERSHIPS, never in entries. A `slate_entries` row
 * is created lazily on a member's FIRST pick, so somebody who has picked
 * nothing has no entry row and no pick rows — and they are precisely the
 * person a reminder exists for. Any query rooted in `slate_entries` reminds
 * only the people who already played.
 */
class PickReminders
{
    /** A day out from the first kickoff. */
    public const WAVE_REMIND = 'remind';

    /** Ninety minutes out, and the last thing anybody is told. */
    public const WAVE_LAST_CALL = 'last_call';

    /**
     * The published slates whose first kickoff falls inside this wave's
     * window and which have not been swept for it yet.
     *
     * The window is filtered in PHP rather than SQL for the reason every
     * other clock question in this app is: ET boundaries under DST are not
     * askable in a WHERE clause. The candidate set is a few hundred rows a
     * season, so it costs nothing to be right.
     *
     * @return Collection<int, Slate>
     */
    public static function dueSlates(string $wave): Collection
    {
        $lastCall = $wave === self::WAVE_LAST_CALL;

        $deadline = $lastCall
            ? now()->addMinutes(Cadence::LAST_CALL_MINUTES)
            : now()->addHours(Cadence::REMINDER_LEAD_HOURS);

        return Slate::query()
            ->where('status', Slate::PUBLISHED)
            ->whereNull($lastCall ? 'last_call_sent_at' : 'picks_reminded_at')
            /*
             * A slate published late — Friday night for a Saturday noon kick
             * — blows past the 24-hour window, so wave one correctly fires on
             * the next tick. Without this, wave two lands ninety minutes
             * later: two messages inside twelve hours about a card they only
             * just heard about.
             */
            ->when($lastCall, fn ($query) => $query->where(fn ($nested) => $nested
                ->whereNull('picks_reminded_at')
                ->orWhere('picks_reminded_at', '<=', now()->subHours(Cadence::LAST_CALL_SUPPRESS_HOURS))))
            ->with([
                'games.game:id,kickoff_at,status,completed',
                'contest:id,group_id',
                'contest.group:id,name,kind,week_id',
            ])
            ->get()
            ->filter(function (Slate $slate) use ($deadline) {
                /*
                 * The next OPEN kickoff, not the first ever. Once the noon
                 * games have started the first kickoff is in the past and is
                 * nobody's deadline, but the late card is still pickable —
                 * anchoring on firstKickoff() would drop the whole slate out
                 * of the window the moment its earliest game began, taking
                 * every still-makeable pick with it.
                 */
                $next = $slate->nextKickoff();

                return $next !== null && $next->lessThanOrEqualTo($deadline);
            })
            ->values();
    }

    /**
     * Every reader who still owes picks on these slates, as one card list
     * each, keyed by user id.
     *
     * Four queries total however many slates and members are involved: the
     * memberships, the users, the open slate games, and the picks against
     * them. Never one per row.
     *
     * `$only` narrows the whole computation to one reader — the job's
     * re-ask runs once per recipient, and without it every recipient paid
     * for the entire league's audience to be rebuilt. Null (the sweep)
     * keeps the everyone answer; the gates are identical either way.
     *
     * @param  Collection<int, Slate>  $slates
     * @return array<int, list<array<string, mixed>>>
     */
    public static function owedBy(Collection $slates, ?User $only = null): array
    {
        if ($slates->isEmpty()) {
            return [];
        }

        /*
         * The games still PICKABLE, per slate. The total is not the slate's
         * game count: picks lock game by game at kickoff, so a member who
         * picked eight of ten where the other two have already started is
         * finished, and telling them otherwise reads as a bug.
         */
        $open = $slates->mapWithKeys(fn (Slate $slate) => [
            $slate->id => $slate->games
                ->reject(fn (SlateGame $slateGame) => $slateGame->game?->hasKickedOff() ?? true)
                ->pluck('id')
                ->all(),
        ])->filter(fn (array $ids) => $ids !== []);

        if ($open->isEmpty()) {
            return [];
        }

        $members = self::members($slates->pluck('id')->all(), $only);

        if ($members === []) {
            return [];
        }

        $made = Pick::query()
            ->whereIn('slate_game_id', $open->flatten()->all())
            ->whereIn('user_id', array_keys($members))
            ->get(['slate_game_id', 'user_id'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('slate_game_id')->all());

        $bySlate = $slates->keyBy('id');
        $users = User::query()->whereIn('id', array_keys($members))->get()->keyBy('id');
        $cards = [];

        foreach ($members as $userId => $slateIds) {
            $user = $users->get($userId);

            if ($user === null) {
                continue;
            }

            foreach ($slateIds as $slateId) {
                $openIds = $open->get($slateId, []);

                if ($openIds === []) {
                    continue;
                }

                $owed = count(array_diff($openIds, $made->get($userId, [])));

                if ($owed < 1) {
                    continue;
                }

                $cards[$userId][] = self::card($bySlate->get($slateId), $user, $owed, count($openIds));
            }
        }

        return $cards;
    }

    /**
     * ONE reader's cards, re-read live — the job's second ask.
     *
     * Same gates as the sweep, deliberately: a membership revoked, a pick
     * made, or a kickoff passed between dispatch and run all shrink this to
     * nothing, and nothing is what the job then sends.
     *
     * @param  list<int>  $slateIds
     * @return list<array<string, mixed>>
     */
    public static function cardsFor(User $user, array $slateIds): array
    {
        if ($slateIds === [] || ! self::eligible($user)) {
            return [];
        }

        $slates = Slate::query()
            ->whereIn('id', $slateIds)
            ->where('status', Slate::PUBLISHED)
            ->with([
                'games.game:id,kickoff_at,status,completed',
                'contest:id,group_id',
                'contest.group:id,name,kind,week_id',
            ])
            ->get();

        return self::owedBy($slates, only: $user)[$user->id] ?? [];
    }

    /**
     * The candidate universe: memberships, joined through to the slates.
     *
     * @param  list<int>  $slateIds
     * @return array<int, list<int>> user id => slate ids
     */
    private static function members(array $slateIds, ?User $only = null): array
    {
        return GroupMember::query()
            ->when($only !== null, fn ($query) => $query->where('group_members.user_id', $only->id))
            ->join('contests', 'contests.group_id', '=', 'group_members.group_id')
            ->join('slates', 'slates.contest_id', '=', 'contests.id')
            ->join('users', 'users.id', '=', 'group_members.user_id')
            ->whereIn('slates.id', $slateIds)
            /*
             * MakePick refuses an unverified account and an unclaimed handle,
             * so reminding somebody it would throw on is worse than silence.
             */
            ->whereNotNull('users.email_verified_at')
            ->whereNotNull('users.handle')
            /*
             * THE FLAG MIRROR. While `pickem` is admin-only, a reminder that
             * links a non-admin to their group lands them on the coming-soon
             * screen. Read the CONFIG, never Feature::for($user) — Pennant's
             * database driver persists a row per resolve, which is why the
             * preflight reads the config too.
             */
            ->when(! config('cfb.pickem_open'), fn ($query) => $query->where('users.admin', true))
            ->select('group_members.user_id', 'slates.id as slate_id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('slate_id')->unique()->values()->all())
            ->all();
    }

    /** The per-user half of the sweep's gates, for the job's re-check. */
    private static function eligible(User $user): bool
    {
        if ($user->email_verified_at === null || blank($user->handle)) {
            return false;
        }

        return config('cfb.pickem_open') || $user->admin;
    }

    /** @return array<string, mixed> */
    private static function card(Slate $slate, User $user, int $owed, int $total): array
    {
        $group = $slate->contest->group;

        return [
            'slate_id' => $slate->id,
            'group' => $group->name,
            'owed' => $owed,
            'total' => $total,
            // In the READER's timezone — a kickoff time is only useful if it
            // is the time on their own clock.
            'when' => $slate->nextKickoff()?->setTimezone($user->timezone)->format('D g:ia') ?? '',
            'url' => $group->isRoom()
                ? route('pickem.room', $group)
                : route('pickem.group', $group),
        ];
    }
}
