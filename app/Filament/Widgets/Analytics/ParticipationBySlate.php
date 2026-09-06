<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\AnalyticsCatalog;
use App\Support\Brand;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * Members, entered and complete — per slate, on one Saturday.
 *
 * THE DENOMINATOR IS MEMBERS AT FIRST KICKOFF, and it comes from
 * {@see AnalyticsCatalog::pickemHealth()} so this chart and the ops payload
 * count the room the same way. Two things that would each be wrong on their
 * own:
 *
 *   - Rooting it in `slate_entries` would count only people who already
 *     played, because an entry row is created lazily on a first pick. The
 *     people who entered nothing are the whole finding.
 *   - Counting members NOW rather than at kickoff would let a group that grew
 *     on Sunday make Saturday look like a participation problem.
 *
 * A slate with no first kickoff has no members bar at all, rather than
 * today's roster standing in for a number nothing measured.
 */
class ParticipationBySlate extends ApexChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'participationBySlate';

    protected static ?string $heading = 'Participation by slate';

    protected int|string|array $columnSpan = 8;

    protected static ?int $sort = 3;

    protected ?string $pollingInterval = null;

    protected function getSubheading(): ?string
    {
        return 'Members counted at first kickoff — a room that grew on Sunday did not miss Saturday';
    }

    protected function getOptions(): array
    {
        $saturday = PickemWindow::saturday($this->pageFilters ?? []);

        $rows = collect(app(AnalyticsCatalog::class)->pickemHealth())
            ->where('saturday', $saturday->toDateString())
            ->values();

        return [
            'chart' => ['type' => 'bar', 'height' => 320, 'toolbar' => ['show' => false]],
            'plotOptions' => ['bar' => ['borderRadius' => 3, 'columnWidth' => '60%']],
            'series' => [
                [
                    'name' => 'Members at kickoff',
                    // Null stays null: Apex leaves the column out rather than
                    // drawing a zero-height bar for a number we do not have.
                    'data' => $rows->pluck('members')->all(),
                ],
                ['name' => 'Entered', 'data' => $rows->pluck('entries')->all()],
                ['name' => 'Complete', 'data' => $rows->pluck('entries_complete')->all()],
            ],
            'xaxis' => [
                // The slate id, never the group name — the machine skin drops
                // it and this page has no business printing one either.
                'categories' => $rows->map(fn (array $row): string => 'Slate '.$row['slate_id'])->all(),
                'labels' => ['style' => ['fontFamily' => 'inherit']],
            ],
            'colors' => ['#9ca3af', Brand::color('lager'), '#22c55e'],
            'legend' => ['position' => 'bottom', 'fontFamily' => 'inherit'],
            'dataLabels' => ['enabled' => false],
            'noData' => ['text' => 'No slate on this Saturday'],
        ];
    }
}
