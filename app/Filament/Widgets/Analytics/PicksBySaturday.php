<?php

namespace App\Filament\Widgets\Analytics;

use App\Models\Pick;
use App\Services\CfbCalendar;
use App\Support\Brand;
use Carbon\CarbonImmutable;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * Picks made per Saturday, across the season being played.
 *
 * Converted from the Chart.js `PicksTrendChart`, and BOTH of its decisions
 * are carried over rather than rewritten — the test that pins the second one
 * comes with it.
 *
 * The season comes from `CfbCalendar::currentYear()`, never from a hardcoded
 * year or "the latest season in the table": a season exists in the database
 * months before it is played.
 *
 * A SATURDAY NOBODY HAS PLAYED YET IS ABSENT, not zero. A zero on this line
 * reads as "nobody picked", which is a real and alarming fact; for a Saturday
 * that has not happened it is a fabricated data point wearing a real one's
 * clothes.
 */
class PicksBySaturday extends ApexChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'picksBySaturday';

    protected static ?string $heading = 'Picks by Saturday';

    protected int|string|array $columnSpan = 6;

    protected static ?int $sort = 6;

    protected ?string $pollingInterval = null;

    protected function getSubheading(): ?string
    {
        return 'Season '.app(CfbCalendar::class)->currentYear()
            .'. Saturdays nobody has played yet are not on the line.';
    }

    protected function getOptions(): array
    {
        $rows = Pick::query()
            ->join('slate_games', 'picks.slate_game_id', '=', 'slate_games.id')
            ->join('slates', 'slate_games.slate_id', '=', 'slates.id')
            ->join('contests', 'slates.contest_id', '=', 'contests.id')
            ->where('contests.season_year', app(CfbCalendar::class)->currentYear())
            ->groupBy('slates.saturday')
            ->orderBy('slates.saturday')
            ->selectRaw('slates.saturday as saturday, count(*) as total')
            ->get();

        return [
            'chart' => ['type' => 'line', 'height' => 300, 'toolbar' => ['show' => false]],
            'series' => [[
                'name' => 'Picks',
                'data' => $rows->map(fn ($row): int => (int) $row->total)->all(),
            ]],
            'xaxis' => [
                'categories' => $rows
                    ->map(fn ($row): string => CarbonImmutable::parse($row->saturday)->format('M j'))
                    ->all(),
                'labels' => ['style' => ['fontFamily' => 'inherit']],
            ],
            'yaxis' => ['labels' => ['style' => ['fontFamily' => 'inherit']]],
            'colors' => [Brand::color('lager')],
            'stroke' => ['curve' => 'smooth', 'width' => 2],
            'markers' => ['size' => 4],
            'legend' => ['show' => false],
            'dataLabels' => ['enabled' => false],
            'noData' => ['text' => 'No picks yet'],
        ];
    }
}
