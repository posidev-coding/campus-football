<?php

namespace App\Support;

use App\Models\Contest;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\User;
use App\Models\Week;
use App\Services\CfbCalendar;
use Illuminate\Support\Collection;

/**
 * THE PICK'EM PULSE — the viewer's week in one lean read, for surfaces
 * that are NOT the picks area: Home's picks strip today, the next-up
 * nudge ladder and the nav presence dot behind it tomorrow.
 *
 * The same one-query-per-concern shape as My Picks' cards() — groups,
 * contests, slates by Slate::onCard(), one pick aggregate, one entry
 * read — minus everything only the picks area pays for (the wins
 * ledger, feasibility). Never a per-row query: three groups cost what
 * one does, and a parity test pins it.
 *
 * Memoized in a static per request (the TeamGlance pattern; flushed in
 * tests/Pest.php's beforeEach). Null-shaped throughout: a closed flag
 * or no memberships is an EMPTY collection after at most one query, and
 * a contest with no published slate on the current card simply has no
 * row — callers skip, they never substitute.
 */
class PickemPulse
{
    /** @var array<int, Collection<int, array<string, mixed>>> keyed by user id */
    private static array $cards = [];

    /**
     * One card per contest with a published slate on the card being
     * played: group, mode, state, made/total, points, entryIn, firstKick.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function cards(User $user): Collection
    {
        return self::$cards[$user->id] ??= self::build($user);
    }

    public static function flush(): void
    {
        self::$cards = [];
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function build(User $user): Collection
    {
        // The commit-11 config mirror, never Feature::active() — this can
        // run on every Home render, and Pennant persists per-user rows.
        if (config('cfb.pickem_open') !== true && ! $user->isAdmin()) {
            return collect();
        }

        $groups = $user->groups()->get();

        if ($groups->isEmpty()) {
            return collect();
        }

        $contests = Contest::query()
            ->whereIn('group_id', $groups->pluck('id'))
            ->where('season_year', app(CfbCalendar::class)->currentYear())
            ->get()
            ->keyBy('group_id');

        if ($contests->isEmpty()) {
            return collect();
        }

        $weekId = app(CfbCalendar::class)->defaultWeekId($contests->first()->season_year);
        $week = $weekId === null ? null : Week::find($weekId);

        if ($week === null) {
            return collect();
        }

        // The card being played is a SATURDAY — never where('week_id') alone.
        $slates = Slate::query()
            ->whereIn('contest_id', $contests->pluck('id'))
            ->onCard($week)
            ->where('status', '!=', Slate::DRAFT)
            ->with('games.game:id,kickoff_at,status,completed')
            ->get()
            ->keyBy('contest_id');

        if ($slates->isEmpty()) {
            return collect();
        }

        $made = Pick::query()
            ->join('slate_games', 'slate_games.id', '=', 'picks.slate_game_id')
            ->whereIn('slate_games.slate_id', $slates->pluck('id'))
            ->where('picks.user_id', $user->id)
            ->groupBy('slate_games.slate_id')
            ->selectRaw('slate_games.slate_id, COUNT(*) AS made, COALESCE(SUM(picks.points), 0) AS pts')
            ->get()
            ->keyBy('slate_id');

        $entries = SlateEntry::query()
            ->whereIn('slate_id', $slates->pluck('id'))
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('slate_id');

        return $groups
            ->map(function ($group) use ($contests, $slates, $made, $entries) {
                $contest = $contests->get($group->id);
                $slate = $contest === null ? null : $slates->get($contest->id);

                if ($slate === null) {
                    return null;
                }

                $tally = $made->get($slate->id);
                $entry = $entries->get($slate->id);

                $state = match (true) {
                    $slate->status === Slate::SETTLED => 'final',
                    $slate->status === Slate::PRELIM => 'prelim',
                    $slate->games->contains(fn ($slateGame) => $slateGame->game->hasKickedOff()) => 'live',
                    default => 'upcoming',
                };

                return [
                    'group' => $group,
                    'mode' => $contest->mode,
                    'state' => $state,
                    'made' => (int) ($tally->made ?? 0),
                    'total' => $slate->games->count(),
                    // Signed: a backfired Woodshed Lock is a real −4.
                    'points' => $state === 'final'
                        ? (int) ($entry->final_points ?? 0)
                        : (int) ($tally->pts ?? 0),
                    // The ENTRY, not just the picks — the same derived rule
                    // MakesPicks::entryComplete() and My Picks' entryIn state.
                    'entryIn' => $slate->status === Slate::PUBLISHED
                        && $slate->games->count() > 0
                        && (int) ($tally->made ?? 0) >= $slate->games->count()
                        && ($slate->tiebreaker_slate_game_id === null || $entry?->tiebreaker_total !== null),
                    'firstKick' => $slate->firstKickoff(),
                ];
            })
            ->filter()
            ->values();
    }
}
