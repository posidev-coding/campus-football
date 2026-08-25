<?php

namespace App\Filament\Widgets;

use App\Models\FeedRun;
use App\Models\WorkbookItem;
use App\Support\CoverageReport;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four numbers that answer "is this thing healthy" before you read a row.
 *
 * Page-scoped: `$isDiscovered = false` keeps it off the dashboard, where it
 * would be the same information a second time.
 */
class SyncSpend extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $day = (int) FeedRun::where('started_at', '>=', now()->subDay())->sum('requests');
        $week = (int) FeedRun::where('started_at', '>=', now()->subWeek())->sum('requests');

        $checks = app(CoverageReport::class)->checks();
        $failing = collect($checks)->where('status', CoverageReport::FAIL)->count();
        $warning = collect($checks)->where('status', CoverageReport::WARN)->count();

        return [
            Stat::make('ESPN requests · 24h', number_format($day))
                // The budget is OURS, not ESPN's — 240/min, chosen ~5x below
                // v3's known-bad burst rate.
                ->description('budget 240/min')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('gray'),

            Stat::make('ESPN requests · 7d', number_format($week))
                ->description('steady state is ~1,600')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('gray'),

            Stat::make('Coverage', $failing > 0 ? "{$failing} failing" : ($warning > 0 ? "{$warning} warning" : 'All clear'))
                ->description($failing > 0 ? 'data is missing where it should not be' : 'expected vs actual agrees')
                ->descriptionIcon($failing > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($failing > 0 ? 'danger' : ($warning > 0 ? 'warning' : 'success')),

            $this->advisor(),
        ];
    }

    /**
     * The advisor's last pass and what is waiting because of it.
     *
     * It reads the same `feed_runs` ledger everything else writes to, which is
     * the whole reason its runs go there — no second store, no second widget.
     * It is NOT on the schedule table beside this: that report introspects our
     * own scheduler, and the advisor's cron lives in Claude Code's cloud.
     */
    private function advisor(): Stat
    {
        $run = FeedRun::latestAdvisorRun();
        $open = WorkbookItem::query()->open()->count();

        if ($run === null) {
            return Stat::make('Advisor', 'Never run')
                ->description('nothing has filed into the workbook yet')
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->color('gray');
        }

        $failed = $run->status === FeedRun::FAILED;

        return Stat::make('Advisor', $run->started_at?->diffForHumans() ?? 'Unknown')
            ->description($failed
                ? 'last pass failed — see Recent failures'
                : ($open === 1 ? '1 item open' : "{$open} items open"))
            ->descriptionIcon($failed ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-clipboard-document-list')
            ->color($failed ? 'danger' : ($open > 0 ? 'warning' : 'success'));
    }
}
