<?php

namespace App\Filament\Widgets\Analytics;

use App\Models\ActivityEvent;
use App\Models\PageViewDaily;
use App\Support\AnalyticsWindow;
use App\Support\Brand;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * Views per day, stacked by who was reading — the shape of a week.
 *
 * DEFERRED, because it is a grouped scan over the whole window and the page
 * should paint before it lands.
 *
 * The staff series appears only when the page's staff toggle is on. That is
 * not tidiness: at pilot scale the founder's own browsing is most of the
 * traffic, so a stacked chart that silently includes it draws one person's
 * afternoon as a trend.
 *
 * DAYS BEFORE THE ROLLUP STARTED ARE ABSENT, never plotted at zero. A zero on
 * this chart says "nobody read anything that day", which is a real and
 * alarming claim; for a day before the sensor shipped it is fabricated. The
 * axis therefore starts at the window's `since`, and the subheading says so.
 */
class TrafficArea extends ApexChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'trafficArea';

    protected static ?string $heading = 'Traffic';

    protected int|string|array $columnSpan = 8;

    protected static ?int $sort = 2;

    protected static bool $deferLoading = true;

    protected ?string $pollingInterval = null;

    /** Neutral, and deliberately not a brand key — the palette has three. */
    private const GUEST_COLOR = '#9ca3af';

    private const STAFF_COLOR = '#6366f1';

    protected function getSubheading(): ?string
    {
        $window = AnalyticsWindow::from($this->pageFilters ?? []);

        return $window->sinceDate() === null
            ? 'No data yet'
            : 'Views per day since '.$window->sinceDate().($window->covered ? '' : ' — shorter than the range');
    }

    protected function getOptions(): array
    {
        $window = AnalyticsWindow::from($this->pageFilters ?? []);
        $staff = (bool) ($this->pageFilters['staff'] ?? false);

        $days = $this->days($window);
        $rows = $this->viewsByDay($window);

        /*
         * The accent is the ONLY brand color on the chart, and it is read at
         * request time so a rebrand reaches this without a rebuild. The other
         * two are fixed neutrals rather than invented brand keys: the palette
         * ships exactly three colors (ink, cream, lager), and
         * `Brand::color('chalk')` is not a muted gray — it is an undefined
         * index.
         */
        $series = [
            $this->series('Members', ActivityEvent::MEMBER, $days, $rows, Brand::color('lager')),
            $this->series('Guests', ActivityEvent::GUEST, $days, $rows, self::GUEST_COLOR),
        ];

        if ($staff) {
            $series[] = $this->series('Staff', ActivityEvent::STAFF, $days, $rows, self::STAFF_COLOR);
        }

        return [
            'chart' => ['type' => 'area', 'height' => 300, 'stacked' => true, 'toolbar' => ['show' => false]],
            'series' => $series,
            'xaxis' => ['categories' => $days, 'labels' => ['rotate' => -45, 'style' => ['fontFamily' => 'inherit']]],
            'yaxis' => ['labels' => ['style' => ['fontFamily' => 'inherit']]],
            'dataLabels' => ['enabled' => false],
            'stroke' => ['curve' => 'smooth', 'width' => 2],
            'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.5, 'opacityTo' => 0.05]],
            /*
             * Read at REQUEST time rather than baked into a compiled asset, so
             * a rebrand in the Branding page reaches this chart with no
             * rebuild — the PicksTrendChart precedent.
             */
            'colors' => array_map(fn (array $one): string => $one['color'], $series),
            'legend' => ['position' => 'bottom'],
            'noData' => ['text' => 'No data yet'],
        ];
    }

    /**
     * One audience's daily counts, aligned to the day axis.
     *
     * @param  list<string>  $days
     * @param  array<string, array<int, int>>  $rows
     * @return array{name: string, data: list<int>, color: string}
     */
    private function series(string $name, int $audience, array $days, array $rows, string $color): array
    {
        return [
            'name' => $name,
            'data' => array_map(fn (string $day): int => $rows[$day][$audience] ?? 0, $days),
            'color' => $color,
        ];
    }

    /**
     * The day axis, starting at the window's `since` — never before it.
     *
     * @return list<string>
     */
    private function days(AnalyticsWindow $window): array
    {
        if ($window->since === null) {
            return [];
        }

        $days = [];

        for ($day = $window->since; $day->lte($window->to); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /** @return array<string, array<int, int>> day => audience => views */
    private function viewsByDay(AnalyticsWindow $window): array
    {
        $out = [];

        $rows = PageViewDaily::query()
            ->whereBetween('day', [$window->fromDate(), $window->toDate()])
            ->groupBy('day', 'audience')
            ->selectRaw('day, audience, sum(views) as views')
            ->get();

        foreach ($rows as $row) {
            $day = CarbonImmutable::parse($row->day)->toDateString();
            $out[$day][(int) $row->audience] = (int) $row->views;
        }

        return $out;
    }
}
