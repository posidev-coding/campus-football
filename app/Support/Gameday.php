<?php

namespace App\Support;

use App\Models\GamedayWeek;
use App\Models\User;
use App\Services\CfbCalendar;
use Carbon\CarbonImmutable;

/**
 * One answer to "which Saturday", shared by the command that writes the row
 * and the card that renders it.
 *
 * It exists because those two must never disagree. A card reading a different
 * Saturday than the command wrote would not look broken — it would look like
 * a feature that simply never has an answer, which is the failure mode that
 * survives longest.
 */
class Gameday
{
    /**
     * The Saturday the NEXT broadcast belongs to.
     *
     * {@see Cadence::currentSaturday()} runs a Tuesday-through-Monday week, so
     * on Sunday and Monday it deliberately looks BACK at the Saturday just
     * played — right for pick'em, where Sunday's results and Monday's arguing
     * still belong to it, and wrong here on exactly the two mornings ESPN
     * usually announces the next stop.
     */
    public static function saturday(?CarbonImmutable $at = null): CarbonImmutable
    {
        $now = ($at ?? CarbonImmutable::now())->timezone(config('cfb.timezone'))->startOfDay();
        $saturday = Cadence::currentSaturday($at);

        return $saturday->lt($now) ? $saturday->addWeek() : $saturday;
    }

    /**
     * What we know about the coming Saturday, or null when nothing has been
     * written for it yet.
     *
     * Eager-loads everything the card reads. A missing eager load has exactly
     * one detector in this application — Pulse's slow queries, in production —
     * because `preventLazyLoading`'s per-instance flag is false under test.
     */
    public static function current(): ?GamedayWeek
    {
        return GamedayWeek::query()
            ->with(['team', 'game.homeTeam', 'game.awayTeam'])
            ->where('season_year', app(CfbCalendar::class)->currentYear())
            ->whereDate('saturday', self::saturday()->toDateString())
            ->first();
    }

    /**
     * Whether the card belongs on the page at all.
     *
     * Off-season it does not render — a dead card for seven months is clutter,
     * not presence, and "GameDay is not on the air" is not news anybody needs
     * repeated every day until August.
     */
    public static function renders(): bool
    {
        return app(CfbCalendar::class)->phase()->isLive();
    }

    /**
     * Is this the reader's own campus?
     *
     * The card gets louder and takes the team's colors when it is, because
     * GameDay coming to a team you follow is a personal event rather than a
     * league headline.
     */
    public static function isFollowed(?GamedayWeek $week, ?User $user): bool
    {
        if ($week?->team_id === null || $user === null) {
            return false;
        }

        return $user->followedTeams()->whereKey($week->team_id)->exists();
    }
}
