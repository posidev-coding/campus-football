<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\AnalyticsCatalog;
use App\Support\AnalyticsWindow;
use App\Support\Brand;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * Registered → verified → onboarded → reached Picks → entered → installed.
 *
 * THE FIRST BAR IS `ux_events`, NOT `users`, and that is the whole
 * correctness of this chart. Unverified accounts prune at fourteen days, so
 * counting rows in `users` silently drops everybody who registered and never
 * came back — precisely the population a lifecycle funnel exists to measure.
 * It would make the drop-off vanish and print the best number on the worst
 * week.
 *
 * COUNTS, NOT PERCENTAGES between the bars. The stages do not share a
 * denominator: the first is an event total and the rest are stamps on
 * surviving accounts, so a percentage between them would divide two different
 * populations and read as precision.
 */
class LifecycleFunnel extends ApexChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'lifecycleFunnel';

    protected static ?string $heading = 'Lifecycle';

    protected int|string|array $columnSpan = 6;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected function getSubheading(): ?string
    {
        $window = AnalyticsWindow::from($this->pageFilters ?? []);

        return 'Registrations counted from the funnel, not from surviving accounts'
            .($window->sinceDate() === null ? '' : ' · since '.$window->sinceDate());
    }

    protected function getOptions(): array
    {
        $steps = app(AnalyticsCatalog::class)->lifecycle(AnalyticsWindow::from($this->pageFilters ?? []));

        $labels = [
            'registered' => 'Registered',
            'verified' => 'Verified',
            'onboarded' => 'Onboarded',
            'reached_picks' => 'Reached Picks',
            'entered' => 'Entered a slate',
            'installed' => 'Installed',
        ];

        return [
            'chart' => ['type' => 'bar', 'height' => 320, 'toolbar' => ['show' => false]],
            'plotOptions' => ['bar' => ['horizontal' => true, 'borderRadius' => 3, 'distributed' => true]],
            'series' => [[
                'name' => 'People',
                'data' => array_map(fn (string $key): int => $steps[$key], array_keys($labels)),
            ]],
            'xaxis' => ['categories' => array_values($labels), 'labels' => ['style' => ['fontFamily' => 'inherit']]],
            'colors' => [Brand::color('lager')],
            'legend' => ['show' => false],
            'dataLabels' => ['enabled' => true, 'style' => ['fontFamily' => 'inherit']],
            'noData' => ['text' => 'No data yet'],
        ];
    }
}
