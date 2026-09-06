<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\AnalyticsCatalog;
use App\Support\Brand;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * Eight registration weeks down, weeks-since across — the grid that says
 * whether anybody comes back.
 *
 * A CELL UNDER TEN PEOPLE IS BLANK, NOT ZERO, and it is the most important
 * rendering decision on this page. A retention grid full of honest-looking
 * zeros is the single most persuasive wrong chart an early product can draw
 * itself: it invites a founder to conclude the product does not retain, when
 * the truth is that four people is not a rate. The row label carries `n` so
 * the blank is legible as "too few" rather than as missing data.
 *
 * DEFERRED — it is the widest read on the page.
 */
class CohortRetentionHeatmap extends ApexChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'cohortRetention';

    protected static ?string $heading = 'Weekly retention by cohort';

    protected int|string|array $columnSpan = 12;

    protected static ?int $sort = 3;

    protected static bool $deferLoading = true;

    protected ?string $pollingInterval = null;

    protected function getSubheading(): ?string
    {
        return 'Cells under '.AnalyticsCatalog::MIN_PEOPLE.' people are left blank — a rate over four people is not a rate';
    }

    protected function getOptions(): array
    {
        $rows = app(AnalyticsCatalog::class)->retention();

        return [
            'chart' => ['type' => 'heatmap', 'height' => 340, 'toolbar' => ['show' => false]],
            // Newest cohort at the top, which is the one anybody is looking
            // for; Apex draws the first series at the bottom.
            'series' => collect($rows)->map(fn (array $row): array => [
                'name' => $row['cohort'].' (n='.$row['size'].')',
                'data' => collect($row['weeks'])
                    ->map(fn (?float $share, int $i): array => [
                        'x' => 'W'.$i,
                        // Null, never 0 — Apex renders a null cell as empty,
                        // which is exactly the reading we want.
                        'y' => $share === null ? null : round($share * 100, 1),
                    ])
                    ->values()
                    ->all(),
            ])->all(),
            'colors' => [Brand::color('lager')],
            'dataLabels' => ['enabled' => true, 'style' => ['fontFamily' => 'inherit']],
            'legend' => ['show' => false],
            'noData' => ['text' => 'No data yet'],
        ];
    }
}
