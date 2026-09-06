<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\AnalyticsCatalog;
use App\Support\AnalyticsWindow;
use App\Support\Brand;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * Which widths the product is actually read at.
 *
 * The whole app is designed at 390px and widened from there, so "which width"
 * is the first question asked of any attention number: a screen that only
 * works above `sm` reads as healthy in a total and as broken in this
 * breakdown.
 *
 * "NOT REPORTED" IS AN EXPLICIT SLICE. The first HTML response of a session
 * is sent before the client cookie exists, so a real share of views genuinely
 * have no width — and folding them into Phone because most readers are on a
 * phone would invent the exact number this chart measures. It is drawn in
 * gray, labeled, and left in the denominator of nothing.
 */
class DeviceMix extends ApexChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'deviceMix';

    protected static ?string $heading = 'Devices';

    protected int|string|array $columnSpan = 6;

    protected static ?int $sort = 5;

    protected ?string $pollingInterval = null;

    protected function getSubheading(): ?string
    {
        $devices = app(AnalyticsCatalog::class)->devices(AnalyticsWindow::from($this->pageFilters ?? []));

        return $devices['installed_share'] === null
            ? 'No view has reported whether it was installed yet'
            : round($devices['installed_share'] * 100).'% of the views that told us were installed';
    }

    protected function getOptions(): array
    {
        $devices = app(AnalyticsCatalog::class)->devices(AnalyticsWindow::from($this->pageFilters ?? []));
        $buckets = $devices['by_bucket'];

        return [
            'chart' => ['type' => 'donut', 'height' => 320],
            'series' => array_values($buckets),
            'labels' => array_map(
                fn (string $key): string => $key === 'unknown' ? 'Not reported' : ucfirst($key),
                array_keys($buckets),
            ),
            // Gray first, because `unknown` is the first bucket and it should
            // never look like a real device class.
            'colors' => ['#9ca3af', Brand::color('lager'), '#6366f1', '#0ea5e9', '#14b8a6'],
            'legend' => ['position' => 'bottom', 'fontFamily' => 'inherit'],
            'dataLabels' => ['enabled' => true, 'style' => ['fontFamily' => 'inherit']],
            'noData' => ['text' => 'No data yet'],
        ];
    }
}
