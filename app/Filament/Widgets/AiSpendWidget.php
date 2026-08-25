<?php

namespace App\Filament\Widgets;

use App\Models\AiSpend;
use App\Support\AiBudget;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Month-to-date AI spend against the ceiling.
 *
 * Page-scoped like the rest of Sync Health's widgets: `$isDiscovered = false`
 * keeps it off the Dashboard, where it would be the same number a second time.
 *
 * Built from Filament's own `Stat` components rather than hand-rolled markup.
 * The panel has its own compiled theme now, so Tailwind here WOULD work — this
 * is a choice rather than the old constraint: a stats row is exactly what
 * `StatsOverviewWidget` is, and matching the three sibling widgets matters
 * more than the freedom to be different.
 */
class AiSpendWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $budget = app(AiBudget::class);

        if (! $budget->enabled()) {
            return [
                Stat::make('AI spend', 'Off')
                    ->description('AI_ENABLED is false — nothing is calling a model')
                    ->descriptionIcon('heroicon-m-power')
                    ->color('gray'),
            ];
        }

        $spent = $budget->spent();
        $fraction = $budget->fraction();

        return [
            Stat::make('AI spend · month to date', '$'.number_format($spent, 2))
                ->description($this->against($budget))
                ->descriptionIcon('heroicon-m-banknotes')
                // Warn well before the ceiling: the point of a budget nobody
                // has hit is noticing the week it starts climbing.
                ->color(match (true) {
                    $fraction === null => 'gray',
                    $fraction >= 1.0 => 'danger',
                    $fraction >= 0.75 => 'warning',
                    default => 'success',
                }),

            Stat::make('Biggest line', $this->biggestLine())
                ->description('by spend this month')
                ->descriptionIcon('heroicon-m-chart-pie')
                ->color('gray'),

            Stat::make('Calls · month to date', number_format($this->calls()))
                ->description($this->perCall($spent))
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color('gray'),
        ];
    }

    private function against(AiBudget $budget): string
    {
        $remaining = $budget->remaining();

        if ($remaining === null) {
            return 'no ceiling set — AI_MONTHLY_BUDGET is zero';
        }

        return $remaining > 0
            ? '$'.number_format($remaining, 2).' left of $'.number_format($budget->budget(), 2)
            : 'ceiling reached — calls are being refused';
    }

    /** Which line of the cost model is actually costing the money. */
    private function biggestLine(): string
    {
        $line = AiSpend::query()
            ->thisMonth()
            ->groupBy('feature')
            ->selectRaw('feature, sum(cost) as total')
            ->orderByDesc('total')
            ->first();

        return $line === null ? '—' : $line->feature.' · $'.number_format((float) $line->total, 2);
    }

    private function calls(): int
    {
        return AiSpend::query()->thisMonth()->count();
    }

    private function perCall(float $spent): string
    {
        $calls = $this->calls();

        return $calls === 0
            ? 'nothing spent yet this month'
            : 'averaging $'.number_format($spent / $calls, 4).' a call';
    }
}
