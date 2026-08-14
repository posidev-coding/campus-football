<?php

namespace App\Support;

use App\Models\PickemSetting;
use App\Models\Week;
use Carbon\CarbonImmutable;

/**
 * The league's weekly clock: when a board must be set, and when a week's
 * results turn official.
 *
 * Every moment resolves in the app's football timezone (Eastern) against a
 * WEEK's own Saturday — the cadence belongs to the week being played, so a
 * bye in the calendar never shifts it. Defaults are constants here; the one
 * pickem_settings row overrides them from the admin panel, null meaning
 * "the shipped value" (the brand pattern).
 *
 *   slate deadline   Tuesday end-of-day ET before the Saturday — the moment
 *                    an unpublished board gets the standard slate instead
 *                    of hanging the group out with a blank week
 *   official final   Sunday noon ET after the Saturday — the stat-settling
 *                    window: ESPN occasionally corrects a passing-yards
 *                    total hours after a game, and a tiebreaker must not
 *                    pay out before those land
 */
class Cadence
{
    /** Carbon day-of-week: Tuesday. */
    public const DEADLINE_DOW = 2;

    public const DEADLINE_TIME = '23:59:59';

    /** Carbon day-of-week: Sunday. */
    public const OFFICIAL_DOW = 0;

    public const OFFICIAL_TIME = '12:00:00';

    private static ?PickemSetting $memo = null;

    public static function flush(): void
    {
        self::$memo = null;
    }

    /**
     * The week's Saturday, at midnight ET — the anchor every other moment
     * is measured from. Null for a week that somehow holds no Saturday.
     */
    public static function saturdayOf(Week $week): ?CarbonImmutable
    {
        if ($week->start_date === null || $week->end_date === null) {
            return null;
        }

        $day = $week->start_date->timezone(config('cfb.timezone'))->startOfDay();
        $end = $week->end_date->timezone(config('cfb.timezone'));

        while ($day->lessThanOrEqualTo($end)) {
            if ($day->dayOfWeek === CarbonImmutable::SATURDAY) {
                return $day;
            }

            $day = $day->addDay();
        }

        return null;
    }

    /**
     * When an unpublished board forfeits to the standard slate: the
     * configured day-of-week BEFORE the week's Saturday, at the configured
     * time, ET.
     */
    public static function slateDeadline(Week $week): ?CarbonImmutable
    {
        $saturday = self::saturdayOf($week);

        if ($saturday === null) {
            return null;
        }

        $dow = self::settings()->slate_deadline_dow ?? self::DEADLINE_DOW;
        $time = self::settings()->slate_deadline_time ?? self::DEADLINE_TIME;

        // Days BACK from Saturday (6) to the configured weekday.
        $daysBefore = (6 - $dow + 7) % 7 ?: 7;

        return $saturday->subDays($daysBefore)->setTimeFromTimeString($time);
    }

    /**
     * When the week's results turn official: the configured day-of-week
     * AFTER the Saturday, at the configured time, ET. Settlement and
     * payouts wait for this moment — the stat-settling window.
     */
    public static function officialFinal(Week $week): ?CarbonImmutable
    {
        $saturday = self::saturdayOf($week);

        if ($saturday === null) {
            return null;
        }

        $dow = self::settings()->official_final_dow ?? self::OFFICIAL_DOW;
        $time = self::settings()->official_final_time ?? self::OFFICIAL_TIME;

        $daysAfter = ($dow - 6 + 7) % 7 ?: 7;

        return $saturday->addDays($daysAfter)->setTimeFromTimeString($time);
    }

    private static function settings(): PickemSetting
    {
        return self::$memo ??= PickemSetting::current();
    }
}
