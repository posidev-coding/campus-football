<?php

namespace App\Filament\Widgets\Analytics;

use App\Models\Team;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * The schools people follow, most-followed first.
 *
 * Converted from the Chart.js `TopTeamsChart`, and its two decisions are
 * carried over rather than rewritten.
 *
 * EACH BAR IS THE SCHOOL'S OWN COLOR, falling back to a neutral gray for a
 * team ESPN gave us no color for — a fabricated brand color on a real school
 * is worse than an honest gray.
 *
 * A team NOBODY follows is off the chart entirely, not a zero-length bar.
 */
class TopTeamsBar extends ApexChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'topTeamsBar';

    protected static ?string $heading = 'Most-followed schools';

    protected int|string|array $columnSpan = 6;

    protected static ?int $sort = 8;

    protected ?string $pollingInterval = null;

    /** For a school ESPN gave us no color for. */
    private const NO_COLOR = '#9ca3af';

    protected function getOptions(): array
    {
        $teams = Team::query()
            ->withCount('followers')
            ->orderByDesc('followers_count')
            ->having('followers_count', '>', 0)
            ->limit(10)
            ->get();

        return [
            'chart' => ['type' => 'bar', 'height' => 300, 'toolbar' => ['show' => false]],
            'plotOptions' => ['bar' => ['horizontal' => true, 'borderRadius' => 3, 'distributed' => true]],
            'series' => [['name' => 'Followers', 'data' => $teams->pluck('followers_count')->all()]],
            'xaxis' => ['categories' => $teams->pluck('abbreviation')->all(), 'labels' => ['style' => ['fontFamily' => 'inherit']]],
            'colors' => $teams
                ->map(fn (Team $team): string => $team->color === null ? self::NO_COLOR : '#'.ltrim($team->color, '#'))
                ->all(),
            'legend' => ['show' => false],
            'dataLabels' => ['enabled' => false],
            'noData' => ['text' => 'No data yet'],
        ];
    }
}
