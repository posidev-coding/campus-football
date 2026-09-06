<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\AnalyticsCatalog;
use App\Support\AnalyticsWindow;
use App\Support\Brand;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * Weekly actives, split by how long the person has been here.
 *
 * The question a flat actives line CANNOT answer: is the app holding people,
 * or is every good week a different set of strangers? Both draw the same
 * total. Stacked by cohort age they are unmistakable — a healthy week grows
 * its "older" band, and a week that is all "new" is a week of churn wearing a
 * growth chart.
 */
class ActivesByCohortArea extends ApexChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'activesByCohort';

    protected static ?string $heading = 'Weekly actives by cohort age';

    protected int|string|array $columnSpan = 8;

    protected static ?int $sort = 4;

    protected ?string $pollingInterval = null;

    protected function getSubheading(): ?string
    {
        return 'A week that is all new readers is churn wearing a growth chart';
    }

    protected function getOptions(): array
    {
        $rows = app(AnalyticsCatalog::class)
            ->activesByCohortAge(AnalyticsWindow::from($this->pageFilters ?? []));

        return [
            'chart' => ['type' => 'area', 'height' => 300, 'stacked' => true, 'toolbar' => ['show' => false]],
            'series' => [
                ['name' => 'Older than a month', 'data' => array_column($rows, 'older')],
                ['name' => 'One to four weeks', 'data' => array_column($rows, 'recent')],
                ['name' => 'New this week', 'data' => array_column($rows, 'new')],
            ],
            'xaxis' => ['categories' => array_column($rows, 'week'), 'labels' => ['style' => ['fontFamily' => 'inherit']]],
            'colors' => [Brand::color('lager'), '#6366f1', '#9ca3af'],
            'stroke' => ['curve' => 'smooth', 'width' => 2],
            'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.5, 'opacityTo' => 0.05]],
            'dataLabels' => ['enabled' => false],
            'legend' => ['position' => 'bottom', 'fontFamily' => 'inherit'],
            'noData' => ['text' => 'No data yet'],
        ];
    }
}
