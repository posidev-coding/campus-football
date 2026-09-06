<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\Brand;
use App\Support\Cadence;
use App\Support\LiveState;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * How much of each Saturday's picking happens under the wire.
 *
 * The window is {@see Cadence::LAST_CALL_MINUTES} before first kickoff, read
 * from the constant rather than restated — the last-call notification is
 * timed off the same number, so a chart with its own ninety would silently
 * stop describing the thing the product actually sends.
 *
 * `updated_at`, not `created_at`: changing your mind at 11:58 is a late pick,
 * and the question is whether people are deciding under the wire.
 *
 * A SATURDAY WITH NO PICKS IS ABSENT from the column chart, never plotted at
 * zero — the same rule the picks trend has always followed. Zero here would
 * say people picked early on a day nobody picked at all.
 */
class LatePickShare extends ApexChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'latePickShare';

    protected static ?string $heading = 'Late picks by Saturday';

    protected int|string|array $columnSpan = 4;

    protected static ?int $sort = 4;

    protected ?string $pollingInterval = null;

    /** Saturdays back, enough to see a habit rather than a week. */
    private const SATURDAYS = 6;

    protected function getSubheading(): ?string
    {
        return 'Share made inside the last '.Cadence::LAST_CALL_MINUTES.' minutes before kickoff';
    }

    protected function getOptions(): array
    {
        $current = Cadence::currentSaturday();
        $live = app(LiveState::class);

        $points = [];

        for ($i = self::SATURDAYS - 1; $i >= 0; $i--) {
            $saturday = $current->subWeeks($i);

            $measured = collect($live->build($saturday, names: false)['contests'])
                ->filter(fn (array $row): bool => $row['late_share'] !== null);

            // Absent, not zero: a Saturday nobody picked has no late share.
            if ($measured->isEmpty()) {
                continue;
            }

            $points[] = [
                'x' => $saturday->format('M j'),
                'y' => round($measured->avg('late_share') * 100, 1),
            ];
        }

        return [
            'chart' => ['type' => 'bar', 'height' => 320, 'toolbar' => ['show' => false]],
            'plotOptions' => ['bar' => ['borderRadius' => 3, 'columnWidth' => '55%']],
            'series' => [['name' => 'Late picks', 'data' => $points]],
            'yaxis' => ['max' => 100, 'labels' => ['style' => ['fontFamily' => 'inherit']]],
            'xaxis' => ['labels' => ['style' => ['fontFamily' => 'inherit']]],
            'colors' => [Brand::color('lager')],
            'legend' => ['show' => false],
            'dataLabels' => ['enabled' => false],
            'noData' => ['text' => 'No picks yet'],
        ];
    }
}
