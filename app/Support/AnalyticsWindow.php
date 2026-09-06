<?php

namespace App\Support;

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
    /** The three windows anything in this layer may ask for. */
    public const DAYS = [7, 28, 90];

    /** Four pick'em weeks — the one a dashboard opens on. */
    public const DEFAULT_DAYS = 28;

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

        $first = app(ActivityRollup::class)->since();
        $start = $first === null ? null : CarbonImmutable::parse($first, config('cfb.timezone'))->startOfDay();

        return new self(
            days: $days,
            from: $from,
            to: $to,
            // The LATER of the two: data cannot exist before the sensor did,
            // and a window cannot report days it does not cover.
            since: $start === null ? null : ($start->lt($from) ? $from : $start),
            covered: $start !== null && $start->lte($from),
            label: $days.'d',
        );
    }

    /**
     * The window a dashboard's filter names.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function from(array $filters): self
    {
        return self::of((int) ($filters['window'] ?? self::DEFAULT_DAYS));
    }

    /** The filter's own options, so a select and this class cannot disagree. */
    /** @return array<int, string> */
    public static function options(): array
    {
        return collect(self::DAYS)->mapWithKeys(fn (int $days) => [$days => $days.' days'])->all();
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
