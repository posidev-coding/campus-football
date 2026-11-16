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
 * DEFERRED, and read through {@see AnalyticsCatalog::attention()}, which
 * counts the same member page views over the same span as `routes()`.
 * Members only, staff excluded — that decision lives in the catalog, once,
 * rather than being re-argued here. Not `routes.top`: top drops every route
 * under five views so it cannot overlap `quiet`, and the slivers are what
 * this chart is for.
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

    /**
     * The section's own `since`, not the window's: route counts come off the
     * raw stream, which keeps thirty days, so a 90-day range starts where
     * that table does and the label has to say so.
     */
    protected function getSubheading(): ?string
    {
        $since = $this->attention()['since'];

        return $since === null
            ? 'No data yet'
            : 'Member views since '.$since;
    }

    protected function getOptions(): array
    {
        $attention = $this->attention();

        return [
            'chart' => ['type' => 'treemap', 'height' => 300, 'toolbar' => ['show' => false]],
            'series' => [[
                'data' => array_map(
                    fn (array $row): array => ['x' => $row['route'], 'y' => $row['views']],
                    $attention['routes'],
                ),
            ]],
            'colors' => [Brand::color('lager')],
            'legend' => ['show' => false],
            'dataLabels' => ['enabled' => true, 'style' => ['fontFamily' => 'inherit']],
            'plotOptions' => ['treemap' => ['distributed' => false, 'enableShades' => true]],
            'noData' => ['text' => 'No data yet'],
        ];
    }

    /** @return array{since: ?string, routes: list<array{route: string, views: int}>} */
    private function attention(): array
    {
        return app(AnalyticsCatalog::class)->attention(AnalyticsWindow::from($this->pageFilters ?? []));
    }
}
