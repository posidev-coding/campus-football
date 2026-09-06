<?php

namespace App\Filament\Widgets\Analytics;

use App\Models\Slate;
use App\Support\AnalyticsCatalog;
use App\Support\Brand;
use App\Support\Cadence;
use App\Support\LiveState;
use Carbon\CarbonImmutable;
use Filament\Support\RawJs;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * When each slate's picking actually happened, from publish to first kickoff.
 *
 * A range bar per slate: the first pick to the last, against the clock the
 * product itself runs on. The deadline, the reminder wave and the last-call
 * window are drawn as annotations, because the whole value of the chart is
 * seeing the bar sit AFTER the reminder line rather than before it — the
 * question "did the wave move anybody" has a shape, not just a number.
 *
 * The annotations go through `extraJsOptions` because Apex's own annotation
 * objects carry callbacks and this widget's options are serialized to JSON.
 *
 * A SLATE NOBODY PICKED HAS NO BAR, rather than a zero-width one at the
 * publish stamp — which would draw everybody picking the instant it opened.
 *
 * DEFERRED: it reads every pick on the Saturday.
 */
class PickTiming extends ApexChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?string $chartId = 'pickTiming';

    protected static ?string $heading = 'When picks were made';

    protected int|string|array $columnSpan = 6;

    protected static ?int $sort = 5;

    protected static bool $deferLoading = true;

    protected ?string $pollingInterval = null;

    protected function getSubheading(): ?string
    {
        return 'First pick to last, against the reminder and the last '
            .Cadence::LAST_CALL_MINUTES.' minutes';
    }

    protected function getOptions(): array
    {
        $saturday = PickemWindow::saturday($this->pageFilters ?? []);
        $contests = collect(app(LiveState::class)->build($saturday, names: false)['contests']);

        $bars = [];

        foreach ($contests as $row) {
            $timing = app(AnalyticsCatalog::class)->pickTiming($row['slate_id']);

            // No picks means no bar. A zero-width bar at the publish stamp
            // would draw everybody picking the instant the slate opened.
            if ($timing['picks'] === 0 || $timing['first_at'] === null) {
                continue;
            }

            $bars[] = [
                'x' => 'Slate '.$row['slate_id'],
                'y' => [
                    CarbonImmutable::parse($timing['first_at'])->getTimestampMs(),
                    CarbonImmutable::parse($timing['last_at'])->getTimestampMs(),
                ],
            ];
        }

        return [
            'chart' => ['type' => 'rangeBar', 'height' => 320, 'toolbar' => ['show' => false]],
            'plotOptions' => ['bar' => ['horizontal' => true, 'borderRadius' => 3, 'barHeight' => '50%']],
            'series' => [['name' => 'Picking', 'data' => $bars]],
            'xaxis' => ['type' => 'datetime', 'labels' => ['style' => ['fontFamily' => 'inherit']]],
            'colors' => [Brand::color('lager')],
            'legend' => ['show' => false],
            'dataLabels' => ['enabled' => false],
            'noData' => ['text' => 'No picks on this Saturday'],
        ];
    }

    /**
     * The clock, as annotations.
     *
     * Emitted as raw JS rather than through `getOptions()` so the stamps stay
     * out of the serialized option array — and so a future callback-bearing
     * annotation has somewhere to live.
     */
    protected function extraJsOptions(): ?RawJs
    {
        $saturday = PickemWindow::saturday($this->pageFilters ?? []);
        $lines = [];

        $deadline = Cadence::slateDeadline($saturday);

        if ($deadline !== null) {
            $lines[] = $this->line($deadline->getTimestampMs(), 'Deadline', '#6366f1');
        }

        $slate = Slate::query()
            ->where('saturday', $saturday->toDateString())
            ->whereNotNull('picks_reminded_at')
            ->orderBy('picks_reminded_at')
            ->first();

        if ($slate?->picks_reminded_at !== null) {
            $lines[] = $this->line(
                CarbonImmutable::parse($slate->picks_reminded_at)->getTimestampMs(),
                'Reminder',
                '#22c55e',
            );
        }

        $kickoff = $slate?->firstKickoff();

        if ($kickoff !== null) {
            $lines[] = $this->line(
                CarbonImmutable::parse($kickoff)->subMinutes(Cadence::LAST_CALL_MINUTES)->getTimestampMs(),
                'Last call',
                '#ef4444',
            );
        }

        return RawJs::make('{ annotations: { xaxis: ['.implode(',', $lines).'] } }');
    }

    private function line(int $at, string $label, string $color): string
    {
        return sprintf(
            '{ x: %d, borderColor: "%s", label: { text: "%s", style: { background: "%s", color: "#fff" } } }',
            $at,
            $color,
            $label,
            $color,
        );
    }
}
