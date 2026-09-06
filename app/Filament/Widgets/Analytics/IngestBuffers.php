<?php

namespace App\Filament\Widgets\Analytics;

use App\Actions\RecordActivity;
use App\Actions\RecordUxEvent;
use App\Enums\UxSignal;
use App\Support\OpsReport;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What is sitting in Redis, not yet in MySQL.
 *
 * THE MOST IMPORTANT NUMBER ON THIS PAGE, and the least obvious. A stalled
 * drain is indistinguishable from a quiet week on every widget the rollups
 * feed — both render as no rows, nothing throws, nothing 500s. The buffer is
 * the only tell, which is why it gets a card of its own rather than being one
 * line in the table above.
 *
 * NULL IS "COULD NOT ASK", NEVER 0. An unreachable Redis returning a
 * confident zero would read as "the drain is perfectly caught up", which is
 * the exact opposite of what it means.
 */
class IngestBuffers extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 12;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $activity = app(RecordActivity::class)->pending();

        return [
            Stat::make('Page views buffered', $activity === null ? 'unreachable' : number_format($activity))
                ->description($this->activityDescription($activity))
                ->color($this->activityColor($activity)),

            Stat::make("Today's funnel counters", $this->funnelToday())
                ->description('Counted in Redis, rolled into ux_events overnight')
                ->color('gray'),
        ];
    }

    private function activityDescription(?int $buffered): string
    {
        return match (true) {
            $buffered === null => 'Could not reach the telemetry Redis database',
            $buffered >= OpsReport::ACTIVITY_FAIL => 'Above the trim threshold — the stream is dropping the oldest entries',
            $buffered >= OpsReport::ACTIVITY_WARN => 'Backing up — run php artisan cfb:activity-drain',
            $buffered === 0 => 'Empty — the drain is keeping up',
            default => 'Waiting for the next drain',
        };
    }

    private function activityColor(?int $buffered): string
    {
        return match (true) {
            $buffered === null => 'danger',
            $buffered >= OpsReport::ACTIVITY_FAIL => 'danger',
            $buffered >= OpsReport::ACTIVITY_WARN => 'warning',
            default => 'success',
        };
    }

    /**
     * Today's funnel counters, summed — a liveness read on the OTHER Redis
     * pipeline, so a dead `ux_events` rollup is visible on the same screen.
     */
    private function funnelToday(): string
    {
        $events = app(RecordUxEvent::class);

        $total = collect(UxSignal::cases())
            ->sum(fn (UxSignal $signal): int => $events->todayCount($signal));

        return number_format($total);
    }
}
