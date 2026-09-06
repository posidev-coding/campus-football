<?php

namespace App\Filament\Widgets\Analytics;

use App\Models\UserDay;
use App\Support\AnalyticsCatalog;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Who is here — daily, weekly, monthly, and how much of the month is any
 * given day.
 *
 * The numbers come from {@see AnalyticsCatalog}, not from a query written
 * here, so this card and the ops snapshot cannot report different actives.
 *
 * "NO DATA" IS PRINTED, NEVER 0. Stickiness is null below the floor and null
 * before the rollup has covered anything, and a stat card showing 0% would be
 * the most alarming possible rendering of "we have not measured this yet".
 * The sparkline is the same rule in a chart: days before the sensor existed
 * are absent from the line rather than plotted at zero.
 */
class ActivesStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 12;

    protected ?string $pollingInterval = null;

    /** Days of sparkline behind each stat. */
    private const TREND_DAYS = 14;

    protected function getStats(): array
    {
        $actives = app(AnalyticsCatalog::class)->actives();
        $daily = $this->dailyTrend();

        return [
            Stat::make('Daily actives', (string) $actives['dau'])
                ->description('People who did something today')
                ->chart($daily)
                ->color('success'),

            Stat::make('Weekly actives', (string) $actives['wau'])
                ->description('This pick\'em week, Tuesday through Monday')
                ->chart($daily)
                ->color('info'),

            Stat::make('Monthly actives', (string) $actives['mau'])
                ->description('28 days — four whole pick\'em weeks')
                ->chart($daily),

            Stat::make(
                'Stickiness',
                $actives['stickiness_28d'] === null
                    ? 'no data'
                    : round($actives['stickiness_28d'] * 100).'%',
            )
                ->description($actives['stickiness_28d'] === null
                    ? 'Too few people to divide yet'
                    : 'Mean daily actives over monthly, '.$actives['covered_days'].' days covered')
                ->color($actives['stickiness_28d'] === null ? 'gray' : 'warning'),
        ];
    }

    /**
     * Distinct people per league day, oldest first.
     *
     * A day with no row contributes 0 to the LINE — which is honest here and
     * only here, because `user_days` holds a row for everybody who did
     * anything: an absent day inside the covered window really is a day
     * nobody came. Days BEFORE the rollup started are a different matter, and
     * they are dropped rather than drawn.
     *
     * @return list<int>
     */
    private function dailyTrend(): array
    {
        $today = CarbonImmutable::now(config('cfb.timezone'))->startOfDay();
        $from = $today->subDays(self::TREND_DAYS - 1);

        $rows = UserDay::query()
            ->where('day', '>=', $from->toDateString())
            ->groupBy('day')
            ->selectRaw('day, count(*) as people')
            ->pluck('people', 'day');

        $since = app(AnalyticsCatalog::class)->actives()['since'];

        $trend = [];

        for ($day = $from; $day->lte($today); $day = $day->addDay()) {
            $date = $day->toDateString();

            if ($since !== null && $date < $since) {
                continue;
            }

            $trend[] = (int) ($rows[$date] ?? $rows[$date.' 00:00:00'] ?? 0);
        }

        return $trend;
    }
}
