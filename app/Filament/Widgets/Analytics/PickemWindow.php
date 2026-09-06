<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\AnalyticsWindow;
use App\Support\Cadence;
use Carbon\CarbonImmutable;

/**
 * The Saturday a Pick'em widget is looking at.
 *
 * One reader for the page filter, for the reason {@see AnalyticsWindow}
 * exists one layer over: six widgets each parsing `$pageFilters['saturday']`
 * themselves is six chances to disagree about which day the page is showing,
 * and the disagreement would be invisible — every widget would render a
 * perfectly plausible number for a different date.
 *
 * A missing or unparsable filter falls back to the current pick'em Saturday
 * rather than to today: Sunday and Monday still belong to the Saturday just
 * played, which is what `Cadence` already knows and what a bare `today()`
 * would get wrong two days in seven.
 */
class PickemWindow
{
    /** @param  array<string, mixed>  $filters */
    public static function saturday(array $filters): CarbonImmutable
    {
        $chosen = $filters['saturday'] ?? null;

        if (! is_string($chosen) || $chosen === '') {
            return Cadence::currentSaturday();
        }

        try {
            // Re-pinned in league time, never converted — a calendar date
            // converted from UTC lands at 20:00 the previous evening.
            return CarbonImmutable::parse($chosen, config('cfb.timezone'))->startOfDay();
        } catch (\Throwable) {
            return Cadence::currentSaturday();
        }
    }
}
