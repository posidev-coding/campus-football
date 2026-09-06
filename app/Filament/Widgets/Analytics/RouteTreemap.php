<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\AnalyticsCatalog;
use App\Support\AnalyticsWindow;
use App\Support\Brand;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * Where the attention goes, as area — the one chart that answers "does
 * anybody open Rankings" at a glance.
 *
 * A treemap rather than a bar chart because the question is proportion, not
 * ranking: forty routes in a bar chart is a scroll, and the thing worth
 * seeing is that one screen is most of the app and eleven are slivers.
 *
 * DEFERRED, and read through {@see AnalyticsCatalog::routes()} so the panel
 * and the snapshot rank routes identically. Members only, staff excluded —
 * that decision lives in the catalog, once, rather than being re-argued here.
 */
class RouteTreemap extends ApexChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'routeTreemap';

    protected static ?string $heading = 'Screens by attention';

    protected int|string|array $columnSpan = 4;

    protected static ?int $sort = 4;

    protected static bool $deferLoading = true;

    protected ?string $pollingInterval = null;

    protected function getSubheading(): ?string
    {
        $window = AnalyticsWindow::from($this->pageFilters ?? []);

        return $window->sinceDate() === null
            ? 'No data yet'
            : 'Member views since '.$window->sinceDate();
    }

    protected function getOptions(): array
    {
        $window = AnalyticsWindow::from($this->pageFilters ?? []);
        $routes = app(AnalyticsCatalog::class)->routes($window);

        return [
            'chart' => ['type' => 'treemap', 'height' => 300, 'toolbar' => ['show' => false]],
            'series' => [[
                'data' => array_map(
                    fn (array $row): array => ['x' => $row['route'], 'y' => $row['views']],
                    $routes['top'],
                ),
            ]],
            'colors' => [Brand::color('lager')],
            'legend' => ['show' => false],
            'dataLabels' => ['enabled' => true, 'style' => ['fontFamily' => 'inherit']],
            'plotOptions' => ['treemap' => ['distributed' => false, 'enableShades' => true]],
            'noData' => ['text' => 'No data yet'],
        ];
    }
}
