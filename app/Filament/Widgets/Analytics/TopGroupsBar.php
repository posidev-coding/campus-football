<?php

namespace App\Filament\Widgets\Analytics;

use App\Models\Group;
use App\Support\Brand;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * The rooms people are actually in, biggest first.
 *
 * Converted from the Chart.js `TopGroupsChart`, which stays parked until this
 * replaces it on the page. The BEHAVIOR is carried over deliberately: an
 * empty group is left OFF the chart rather than drawn as a zero-length bar,
 * because a bar of length zero still reads as a room somebody made and
 * abandoned, and the list is meant to answer "where are people".
 */
class TopGroupsBar extends ApexChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'topGroupsBar';

    protected static ?string $heading = 'Biggest rooms';

    protected int|string|array $columnSpan = 6;

    protected static ?int $sort = 7;

    protected ?string $pollingInterval = null;

    protected function getOptions(): array
    {
        $groups = Group::query()
            ->withCount('members')
            ->orderByDesc('members_count')
            ->having('members_count', '>', 0)
            ->limit(10)
            ->get();

        return [
            'chart' => ['type' => 'bar', 'height' => 300, 'toolbar' => ['show' => false]],
            'plotOptions' => ['bar' => ['horizontal' => true, 'borderRadius' => 3]],
            'series' => [['name' => 'Members', 'data' => $groups->pluck('members_count')->all()]],
            'xaxis' => ['categories' => $groups->pluck('name')->all(), 'labels' => ['style' => ['fontFamily' => 'inherit']]],
            // Read at request time, so a rebrand reaches the chart with no
            // rebuild — the precedent the widget it replaces already set.
            'colors' => [Brand::color('lager')],
            'legend' => ['show' => false],
            'dataLabels' => ['enabled' => false],
            'noData' => ['text' => 'No data yet'],
        ];
    }
}
