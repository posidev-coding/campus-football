<?php

namespace App\Support;

use App\Models\Season;
use App\Services\CfbCalendar;
use Carbon\CarbonImmutable;

/**
 * How far back a number looks, and how far back the sensor could actually
 * see — one value object, so no two widgets can disagree about what "28d"
 * means.
 *
 * TWENTY-EIGHT, NOT THIRTY. The pick'em week turns over on a Tuesday
 * (`Cadence::TURNOVER_DOW`), so four whole weeks is 28 days and a 30-day
 * window holds 4.3 Saturdays — which makes every "per week" number in it a
 * fraction of a week nobody played, and makes two adjacent months
 * incomparable for no reason anybody could see on the chart.
 *
 * `since` is the other half, and it is the `funnel_since` rule generalized:
 * a window that starts before the sensor did is not that window's number. A
 * page-view count for "90d" on a sensor that shipped a fortnight ago is a
 * two-week count wearing a three-month label, and it reads as a collapse in
 * traffic that never happened. So every window carries the first day data
 * exists for, and {@see covered()} says whether the label can be believed.
 */
class AnalyticsWindow
{
    /** The three rolling windows anything in this layer may ask for. */
    public const DAYS = [7, 28, 90];

    /** Four pick'em weeks — the one a dashboard opens on. */
    public const DEFAULT_DAYS = 28;

    /**
     * The fourth range, and the only one that is not a fixed width: the season
     * being played, from `seasons.start_date` to today.
     *
     * It exists because "this season" is the question anybody actually asks
     * about a college football product, and a 90-day window answers it wrong
     * in both directions — it includes August in December and excludes
     * September in January.
     */
    public const SEASON = 'season';

    /** The filter's default, as the token a Select stores. */
    public const DEFAULT_RANGE = '28d';

    /**
     * @param  int  $days  the window's width, in league days
     * @param  CarbonImmutable  $from  the first league day counted, inclusive
     * @param  CarbonImmutable  $to  the last, inclusive — today, partial
     * @param  CarbonImmutable|null  $since  the first day the data actually starts, or null when there is none
     * @param  bool  $covered  whether the sensor covered the whole window
     */
    private function __construct(
        public readonly int $days,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?CarbonImmutable $since,
        public readonly bool $covered,
        public readonly string $label,
    ) {}

    /**
     * A window of the given width, ending today.
     *
     * Anything outside {@see DAYS} falls back to the default rather than
     * being honored: this reads a filter, filters come off a URL, and a
     * `?window=4000` would quietly render a four-thousand-day chart labeled
     * as one.
     */
    public static function of(int $days): self
    {
        $days = in_array($days, self::DAYS, true) ? $days : self::DEFAULT_DAYS;

        $to = CarbonImmutable::now(config('cfb.timezone'))->startOfDay();
        // Inclusive of today, so "7d" is seven league days on the chart and
        // not eight.
        $from = $to->subDays($days - 1);

        return self::between($from, $to, $days.'d');
    }

    /**
     * The one constructor that reads `since` — every range resolves through
     * here, so a rolling window and the season window cannot disagree about
     * what "the sensor was not counting yet" means.
     */
    private static function between(CarbonImmutable $from, CarbonImmutable $to, string $label): self
    {
        $first = app(ActivityRollup::class)->since();
        $start = $first === null ? null : CarbonImmutable::parse($first, config('cfb.timezone'))->startOfDay();

        return new self(
            days: $from->diffInDays($to) + 1,
            from: $from,
            to: $to,
            // The LATER of the two: data cannot exist before the sensor did,
            // and a window cannot report days it does not cover.
            since: $start === null ? null : ($start->lt($from) ? $from : $start),
            covered: $start !== null && $start->lte($from),
            label: $label,
        );
    }

    /**
     * The season being played, from its own start date to today.
     *
     * The year comes from {@see CfbCalendar} and never from "the latest row
     * in seasons" — a season exists in the database months before it is
     * played. With no season row at all this falls back to the default
     * rolling window rather than inventing a start.
     */
    public static function season(): self
    {
        $year = app(CfbCalendar::class)->currentYear();
        $start = Season::query()->where('year', $year)->value('start_date');

        if ($start === null) {
            return self::of(self::DEFAULT_DAYS);
        }

        $to = CarbonImmutable::now(config('cfb.timezone'))->startOfDay();
        // A `date` cast arrives as midnight UTC, so the calendar date is
        // RE-PINNED in league time rather than converted — converting lands at
        // 20:00 the previous evening, the trap `Cadence` already draws.
        $from = CarbonImmutable::parse(
            CarbonImmutable::parse($start)->toDateString(),
            config('cfb.timezone'),
        )->startOfDay();

        return self::between($from, $to, self::SEASON);
    }

    /**
     * The window a dashboard's filter names.
     *
     * The filter stores a TOKEN ('28d', 'season'), not a number, because one
     * of the four ranges has no fixed width. An unknown token falls back to
     * the default rather than being honored: filters come off a URL, and a
     * `?range=4000d` would quietly render a four-thousand-day chart labeled as
     * one.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function from(array $filters): self
    {
        $range = (string) ($filters['range'] ?? self::DEFAULT_RANGE);

        if ($range === self::SEASON) {
            return self::season();
        }

        return self::of((int) rtrim($range, 'd'));
    }

    /** The filter's own options, so a select and this class cannot disagree. */
    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::DAYS)
            ->mapWithKeys(fn (int $days): array => [$days.'d' => $days.' days'])
            ->put(self::SEASON, 'This season')
            ->all();
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /** The first day with data, as a league date — null when there is none. */
    public function sinceDate(): ?string
    {
        return $this->since?->toDateString();
    }

    /**
     * How many league days this window actually has data for, which is what
     * any per-day average must divide by.
     */
    public function coveredDays(): int
    {
        return $this->since === null ? 0 : $this->since->diffInDays($this->to) + 1;
    }
}
