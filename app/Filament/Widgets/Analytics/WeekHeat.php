<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\AnalyticsCatalog;
use App\Support\AnalyticsWindow;
use App\Support\Brand;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * Hour by weekday — when this app is actually used.
 *
 * LEAGUE HOUR, read off the column the drain wrote and never asked for in
 * SQL: `CONVERT_TZ` does not know about DST the way the drain did at write
 * time, so 01:00 UTC on a Sunday has to land on Saturday at 21:00 and only
 * the stored value gets that right.
 *
 * 168 cells, which is why this is a chart and never enters the ops snapshot —
 * a model handed 168 numbers finds a pattern in them whether or not one is
 * there.
 *
 * DEFERRED: it is a grouped scan of the raw table.
 */
class WeekHeat extends ApexChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'weekHeat';

    protected static ?string $heading = 'When people read';

    protected int|string|array $columnSpan = 6;

    protected static ?int $sort = 6;

    protected static bool $deferLoading = true;

    protected ?string $pollingInterval = null;

    /** Carbon's own scale: Sunday 0 through Saturday 6. */
    private const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    protected function getSubheading(): ?string
    {
        return 'League time, from the hour the drain stored';
    }

    protected function getOptions(): array
    {
        $cells = app(AnalyticsCatalog::class)->timeOfWeek(AnalyticsWindow::from($this->pageFilters ?? []));

        $grid = [];

        foreach ($cells as $cell) {
            $grid[$cell['weekday']][$cell['hour']] = $cell['views'];
        }

        // Saturday first — this is a college football app, and the row
        // anybody looks for should not be at the bottom.
        $series = [];

        foreach (array_reverse(array_keys(self::DAYS), true) as $index => $_) {
            $series[] = [
                'name' => self::DAYS[$index],
                'data' => collect(range(0, 23))
                    ->map(fn (int $hour): array => [
                        'x' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT),
                        'y' => $grid[$index][$hour] ?? 0,
                    ])
                    ->all(),
            ];
        }

        return [
            'chart' => ['type' => 'heatmap', 'height' => 320, 'toolbar' => ['show' => false]],
            'series' => $series,
            'colors' => [Brand::color('lager')],
            'dataLabels' => ['enabled' => false],
            'legend' => ['show' => false],
            'noData' => ['text' => 'No data yet'],
        ];
    }
}
