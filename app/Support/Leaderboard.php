<?php

namespace App\Support;

use App\Models\GroupMember;
use App\Models\Season;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Week;
use App\Services\CfbCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The pick'em area's XP leaderboard — windowed SUMs over wallet_entries,
 * held as PLAIN ARRAYS the TeamGlance way (a cached model is an
 * __PHP_Incomplete_Class on the second request).
 *
 * Windows: THIS WEEK is the seven days ending at the current week's
 * official-final moment, so on Monday the table shows the week that just
 * PAID rather than an empty new one. THIS SEASON spans the year's seasons
 * rows. Bounds resolve from the calendar OUTSIDE the cache, so a fallback
 * can never be pinned.
 *
 * Circles: EVERYONE, or MY GROUPS — the people you share any group with,
 * which is the set your trash talk actually reaches. The identity column
 * never blocks on the handle seam: a handleless user shows as first name
 * plus last initial until they claim one.
 */
class Leaderboard
{
    public const SIZE = 50;

    private const CACHE_SECONDS = 300;

    public const WINDOWS = ['week', 'season', 'all'];

    public const CIRCLES = ['groups', 'everyone'];

    /**
     * The ranked table, top SIZE rows.
     *
     * @return list<array{rank: int, user_id: int, label: string, xp: int}>
     */
    public static function top(string $window, string $circle, User $viewer): array
    {
        [$start, $end] = self::bounds($window);

        $key = $circle === 'groups'
            ? "pickem:leaderboard:{$window}:groups:{$viewer->id}"
            : "pickem:leaderboard:{$window}:everyone";

        return Remember::filled($key, self::CACHE_SECONDS, function () use ($start, $end, $circle, $viewer) {
            $totals = self::sums($start, $end, $circle, $viewer)
                ->orderByDesc('xp')
                ->limit(self::SIZE)
                ->pluck('xp', 'user_id')
                ->map(fn ($xp) => (int) $xp);

            $users = User::query()
                ->whereIn('id', $totals->keys())
                ->get(['id', 'first_name', 'last_name', 'handle'])
                ->keyBy('id');

            $rank = 0;

            return $totals
                ->map(function (int $xp, int $userId) use (&$rank, $users) {
                    $user = $users->get($userId);

                    return [
                        'rank' => ++$rank,
                        'user_id' => $userId,
                        'label' => self::label($user),
                        'xp' => $xp,
                    ];
                })
                ->values()
                ->all();
        });
    }

    /**
     * Where the viewer stands — for pinning their row when it falls off
     * the page. Null when they have earned nothing in the window.
     *
     * @return array{rank: int, xp: int}|null
     */
    public static function rankOf(User $viewer, string $window, string $circle): ?array
    {
        [$start, $end] = self::bounds($window);

        $mine = (int) self::entries($start, $end, $circle, $viewer)
            ->where('user_id', $viewer->id)
            ->sum('xp');

        if ($mine <= 0) {
            return null;
        }

        $ahead = self::sums($start, $end, $circle, $viewer)
            ->havingRaw('SUM(xp) > ?', [$mine])
            ->get()
            ->count();

        return ['rank' => $ahead + 1, 'xp' => $mine];
    }

    /**
     * The window's instants, calendar-resolved and null-open at either
     * end. Public because the tests assert boundaries through it.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function bounds(string $window): array
    {
        if ($window === 'all') {
            return [null, null];
        }

        $calendar = app(CfbCalendar::class);
        $year = $calendar->currentYear();

        if ($window === 'season') {
            $seasons = Season::query()->where('year', $year)->get(['start_date', 'end_date']);

            if ($seasons->isEmpty()) {
                return [null, null];
            }

            return [
                CarbonImmutable::parse($seasons->min('start_date'))->startOfDay(),
                CarbonImmutable::parse($seasons->max('end_date'))->endOfDay(),
            ];
        }

        // week: the seven days ending at the current week's official-final
        // moment — Monday shows the week that just PAID.
        $weekId = $calendar->defaultWeekId($year);
        $official = $weekId === null ? null : Cadence::officialFinal(Week::find($weekId));

        return $official === null
            ? [CarbonImmutable::now()->subDays(7), null]
            : [$official->subDays(7), $official];
    }

    /** The grouped SUM every table and rank reads. */
    private static function sums(?CarbonImmutable $start, ?CarbonImmutable $end, string $circle, User $viewer): Builder
    {
        return self::entries($start, $end, $circle, $viewer)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(xp) AS xp')
            ->havingRaw('SUM(xp) > 0');
    }

    private static function entries(?CarbonImmutable $start, ?CarbonImmutable $end, string $circle, User $viewer): Builder
    {
        return WalletEntry::query()
            ->when($start !== null, fn (Builder $q) => $q->where('created_at', '>=', $start))
            ->when($end !== null, fn (Builder $q) => $q->where('created_at', '<', $end))
            ->when($circle === 'groups', fn (Builder $q) => $q->whereIn(
                'user_id',
                // The co-member set, as a SUBQUERY — never materialized.
                GroupMember::query()
                    ->select('user_id')
                    ->whereIn('group_id', GroupMember::query()
                        ->select('group_id')
                        ->where('user_id', $viewer->id)),
            ));
    }

    /** Handle when claimed; first name + last initial until then. */
    private static function label(?User $user): string
    {
        if ($user === null) {
            return '—';
        }

        if ($user->handle !== null) {
            return '@'.$user->handle;
        }

        $initial = mb_substr((string) $user->last_name, 0, 1);

        return trim($user->first_name.' '.($initial !== '' ? $initial.'.' : ''));
    }
}
