<?php

namespace App\Support;

use App\Models\Game;
use App\Models\PickemSetting;
use App\Models\Season;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The league's weekly clock: when a slate must be set, and when a week's
 * results turn official.
 *
 * Every moment resolves in the app's football timezone (Eastern) against a
 * WEEK's own Saturday — the cadence belongs to the week being played, so a
 * bye in the calendar never shifts it. Defaults are constants here; the one
 * pickem_settings row overrides them from the admin panel, null meaning
 * "the shipped value" (the brand pattern).
 *
 *   slate deadline   Tuesday end-of-day ET before the Saturday — the moment
 *                    an unpublished slate gets the standard card instead
 *                    of hanging the group out with a blank week
 *   official final   Sunday noon ET after the Saturday — the stat-settling
 *                    window: ESPN occasionally corrects a passing-yards
 *                    total hours after a game, and a tiebreaker must not
 *                    pay out before those land
 */
class Cadence
{
    /**
     * Carbon day-of-week: Tuesday — when a pick'em week turns over.
     *
     * The founders' cycle, adopted app-wide 2026-08-20: a pick'em week runs
     * Tuesday 00:00 ET through Monday 23:59 ET and holds exactly one
     * Saturday. Results land Sunday, Monday is for arguing about them, and
     * Tuesday starts the next card.
     */
    public const TURNOVER_DOW = 2;

    /**
     * Carbon day-of-week: Thursday — "games available by Thursday".
     *
     * Moved from Tuesday end-of-day 2026-08-20 with the rest of the
     * Woodshed cadence. The commissioner gets Tuesday and Wednesday to
     * build; players get roughly forty-eight hours with the card.
     */
    public const DEADLINE_DOW = 4;

    public const DEADLINE_TIME = '12:00:00';

    /** Carbon day-of-week: Sunday. */
    public const OFFICIAL_DOW = 0;

    public const OFFICIAL_TIME = '12:00:00';

    private static ?PickemSetting $memo = null;

    /** @var array<int, CarbonImmutable|null> split boundaries, keyed by week id */
    private static array $boundaries = [];

    public static function flush(): void
    {
        self::$memo = null;
        self::$boundaries = [];
    }

    /**
     * Every Saturday in the week that actually holds slate-window games.
     *
     * ESPN's week is a container that USUALLY holds one Saturday. 2026's
     * Week 1 holds two — 8/29 and 9/5 — and opens on an 8/22 that holds
     * none. So "the week's Saturday" is a question with more than one
     * answer, and a caller that wants all of them must be able to ask.
     *
     * The games filter is the whole point: a calendar Saturday nobody plays
     * on is not a pick'em Saturday, and treating it as one is what put
     * Week 1's deadline in the past. The window check runs in PHP because
     * the ET time-of-day boundary shifts under DST and cannot be asked in
     * SQL.
     *
     * @return array<int, CarbonImmutable> ascending, ET midnights
     */
    public static function saturdaysIn(Week $week): array
    {
        return Game::query()
            ->where('week_id', $week->id)
            ->whereNotNull('kickoff_at')
            ->get(['id', 'kickoff_at', 'kickoff_day'])
            ->filter(fn (Game $game) => $game->inSlateWindow())
            ->map(fn (Game $game) => $game->kickoff_at->timezone(config('cfb.timezone'))->startOfDay())
            ->unique(fn (CarbonImmutable $day) => $day->toDateString())
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The week's PRIMARY Saturday — the one carrying the most games.
     *
     * For every ordinary week this is the only Saturday there is. For a
     * split week it is the main card (9/5's sixty-eight games, not 8/29's
     * seven), which is what a caller thinking in weeks means. Ties break
     * earliest. Falls back to the first calendar Saturday in the range only
     * when no game has been synced yet — a scheduled-but-unplayed week is a
     * real state, and refusing to date it would break the builder before a
     * season starts.
     */
    public static function saturdayOf(Week $week): ?CarbonImmutable
    {
        $busiest = Game::query()
            ->where('week_id', $week->id)
            ->whereNotNull('kickoff_at')
            ->get(['id', 'kickoff_at', 'kickoff_day'])
            ->filter(fn (Game $game) => $game->inSlateWindow())
            ->groupBy(fn (Game $game) => $game->kickoff_at->timezone(config('cfb.timezone'))->toDateString())
            ->sortByDesc(fn ($games, string $date) => sprintf('%04d-%s', $games->count(), $date))
            ->keys()
            ->first();

        if ($busiest !== null) {
            return CarbonImmutable::parse($busiest, config('cfb.timezone'))->startOfDay();
        }

        return self::firstCalendarSaturday($week);
    }

    /** The date-range walk, for a week with no synced games to speak for it. */
    private static function firstCalendarSaturday(Week $week): ?CarbonImmutable
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
     * The instant a SPLIT opening week turns over from its first card to
     * its main one — null for every ordinary week.
     *
     * Only the regular season's opening week can split: ESPN folds the
     * fans' "Week 0" into Week 1's range (2026 holds both 8/29 and 9/5),
     * while every later week matches fan numbering one-to-one. The
     * boundary is the Tuesday turnover before the SECOND Saturday at ET
     * midnight — the same clock currentSaturday() keeps — so the app
     * flips cards on Tuesday, never at a kickoff.
     *
     * Memoized per week because display labels resolve inside list loops
     * (History renders one per row) and the Saturday scan behind this is
     * a games query that must not run per row.
     */
    public static function splitBoundary(Week $week): ?CarbonImmutable
    {
        if (array_key_exists($week->id, self::$boundaries)) {
            return self::$boundaries[$week->id];
        }

        return self::$boundaries[$week->id] = self::resolveBoundary($week);
    }

    private static function resolveBoundary(Week $week): ?CarbonImmutable
    {
        if ((int) $week->number !== 1) {
            return null;
        }

        $week->loadMissing('season');

        if ((int) ($week->season?->type ?? 0) !== Season::REGULAR) {
            return null;
        }

        $saturdays = self::saturdaysIn($week);

        if (count($saturdays) < 2) {
            return null;
        }

        // Days BACK from Saturday (6) to the turnover weekday.
        $daysBefore = (6 - self::TURNOVER_DOW + 7) % 7 ?: 7;

        return $saturdays[1]->subDays($daysBefore);
    }

    /**
     * The week number a FAN would put on this card. Ordinary weeks answer
     * with ESPN's own number; a split opening week answers 0 for its first
     * Saturday and the ESPN number for its main one. The 8/22 the range
     * opens with is never anyone's answer.
     *
     * $saturday takes the forms callers actually hold: a `slates.saturday`
     * date cast or plain 'Y-m-d' string (re-pinned to ET midnight, never
     * converted through a timezone), or nothing — which means the week's
     * primary card.
     */
    public static function displayWeekNumber(Week $week, CarbonInterface|string|null $saturday = null): int
    {
        $boundary = self::splitBoundary($week);

        if ($boundary === null) {
            return (int) $week->number;
        }

        $day = match (true) {
            $saturday === null => self::saturdayOf($week),
            is_string($saturday) => CarbonImmutable::parse($saturday, config('cfb.timezone'))->startOfDay(),
            default => self::anchor($saturday),
        };

        return $day !== null && $day->lessThan($boundary) ? 0 : (int) $week->number;
    }

    public static function displayWeekLabel(Week $week, CarbonInterface|string|null $saturday = null): string
    {
        return 'Week '.self::displayWeekNumber($week, $saturday);
    }

    /**
     * The Saturday this pick'em week is ON: the current pick'em Saturday
     * when it belongs to the week, else the week's NEXT unplayed card,
     * else its primary. The next-card arm matters in the run-up to a
     * split opening week — the clock's "current Saturday" is the empty
     * 8/22, and falling straight to the busiest card would sell 9/5 rooms
     * for five days and then flip BACK to 8/29 at the Tuesday turnover.
     * Cards sell in order. The lobby, the stocking sweep, the preflight
     * and the builder all ask this one question — one answer, or the
     * screens drift apart.
     */
    public static function activeSaturday(Week $week): ?CarbonImmutable
    {
        $current = self::currentSaturday();
        $saturdays = collect(self::saturdaysIn($week));

        return $saturdays->first(fn (CarbonImmutable $day) => $day->toDateString() === $current->toDateString())
            ?? $saturdays->first(fn (CarbonImmutable $day) => $day->greaterThan($current))
            ?? self::saturdayOf($week);
    }

    /**
     * The Saturday of the pick'em week we are currently inside.
     *
     * Tuesday through Monday, so Sunday's results and Monday's arguing
     * still belong to the Saturday just played, and Tuesday moves on.
     */
    public static function currentSaturday(?CarbonImmutable $at = null): CarbonImmutable
    {
        $now = ($at ?? CarbonImmutable::now())->timezone(config('cfb.timezone'))->startOfDay();

        // Carbon: Sun 0 … Sat 6. Sunday and Monday look BACK at the
        // Saturday just played; Tuesday onward looks forward to the next.
        $offset = match ($now->dayOfWeek) {
            CarbonImmutable::SUNDAY => -1,
            CarbonImmutable::MONDAY => -2,
            default => CarbonImmutable::SATURDAY - $now->dayOfWeek,
        };

        return $now->addDays($offset);
    }

    /**
     * Normalize either accepted anchor to a Saturday at ET midnight.
     *
     * A date is taken as a CALENDAR DATE and re-pinned to ET midnight, never
     * converted through a timezone. `slates.saturday` is a date column and
     * arrives as UTC midnight; shifting that into Eastern lands on 8pm the
     * PREVIOUS DAY, and the deadline then counts back from a Friday — one
     * day early, every week, silently.
     */
    private static function anchor(Week|CarbonInterface $for): ?CarbonImmutable
    {
        return $for instanceof Week
            ? self::saturdayOf($for)
            : CarbonImmutable::parse($for->format('Y-m-d'), config('cfb.timezone'))->startOfDay();
    }

    /**
     * When an unpublished slate forfeits to the standard card: the
     * configured day-of-week BEFORE the week's Saturday, at the configured
     * time, ET.
     */
    public static function slateDeadline(Week|CarbonInterface $for): ?CarbonImmutable
    {
        $saturday = self::anchor($for);

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
    public static function officialFinal(Week|CarbonInterface $for): ?CarbonImmutable
    {
        $saturday = self::anchor($for);

        if ($saturday === null) {
            return null;
        }

        $dow = self::settings()->official_final_dow ?? self::OFFICIAL_DOW;
        $time = self::settings()->official_final_time ?? self::OFFICIAL_TIME;

        $daysAfter = ($dow - 6 + 7) % 7 ?: 7;

        return $saturday->addDays($daysAfter)->setTimeFromTimeString($time);
    }

    /**
     * The configured slate deadline as a plain weekday-and-time label, for
     * anything reporting the league's clock rather than resolving a moment
     * on it (the preflight, the settings page's own summary line).
     */
    public static function deadlineLabel(): string
    {
        return self::label(
            self::settings()->slate_deadline_dow ?? self::DEADLINE_DOW,
            self::settings()->slate_deadline_time ?? self::DEADLINE_TIME,
        );
    }

    public static function officialLabel(): string
    {
        return self::label(
            self::settings()->official_final_dow ?? self::OFFICIAL_DOW,
            self::settings()->official_final_time ?? self::OFFICIAL_TIME,
        );
    }

    private static function label(int $dow, string $time): string
    {
        $day = CarbonImmutable::now()->startOfWeek(CarbonInterface::SUNDAY)->addDays($dow);

        return $day->format('D').' '.CarbonImmutable::createFromTimeString($time)->format('g:ia').' ET';
    }

    private static function settings(): PickemSetting
    {
        return self::$memo ??= PickemSetting::current();
    }
}
