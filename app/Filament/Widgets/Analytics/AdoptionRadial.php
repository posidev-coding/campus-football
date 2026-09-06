<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\AnalyticsCatalog;
use App\Support\AnalyticsWindow;
use App\Support\Brand;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * What share of the people who were here this week did each thing.
 *
 * The denominator is weekly ACTIVES, not registered accounts — "do the people
 * who are here use this" is a different question from "do the people who
 * signed up in March", and only the first is actionable this week.
 *
 * BELOW THE FLOOR THE CHART IS EMPTY AND SAYS SO. The catalog returns null
 * shares under ten weekly actives, and a radial bar drawn at 0% would be the
 * most confident possible rendering of "we cannot tell yet".
 */
class AdoptionRadial extends ApexChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'adoptionRadial';

    protected static ?string $heading = 'Feature adoption';

    protected int|string|array $columnSpan = 6;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    protected function getSubheading(): ?string
    {
        $adoption = app(AnalyticsCatalog::class)->adoption(AnalyticsWindow::from($this->pageFilters ?? []));

        return $adoption['wau'] < AnalyticsCatalog::MIN_PEOPLE
            ? 'Too few weekly actives to read a share — '.$adoption['wau'].' so far'
            : 'Share of '.$adoption['wau'].' weekly actives';
    }

    protected function getOptions(): array
    {
        $adoption = app(AnalyticsCatalog::class)->adoption(AnalyticsWindow::from($this->pageFilters ?? []));

        // Only the features with a readable share are drawn. A null is not a
        // zero-length bar; it is a bar there is no number for.
        $readable = collect($adoption['features'])->filter(fn (array $f): bool => $f['share'] !== null);

        return [
            'chart' => ['type' => 'radialBar', 'height' => 340],
            'series' => $readable->map(fn (array $f): float => round($f['share'] * 100, 1))->values()->all(),
            'labels' => $readable->keys()->map(fn (string $k): string => ucfirst(str_replace('_', ' ', $k)))->all(),
            'colors' => [Brand::color('lager')],
            'plotOptions' => ['radialBar' => ['dataLabels' => ['total' => ['show' => false]]]],
            'legend' => ['show' => true, 'position' => 'bottom', 'fontFamily' => 'inherit'],
            'noData' => ['text' => 'No data yet'],
        ];
    }
}
