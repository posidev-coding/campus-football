<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\LiveState;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * Did the reminder wave move anybody?
 *
 * Entries created after `picks_reminded_at` over the people who COULD have
 * been moved — members at the moment of the wave who had no entry yet.
 *
 * THE DENOMINATOR ROOTS IN `group_members`, never in `slate_entries`. An
 * entry row is created lazily on a member's first pick, so somebody who has
 * picked nothing has no entry at all — and that person IS the reminder's
 * audience. Rooting in entries would measure the lift only on people who had
 * already played, which is the implementation that looks correct and silently
 * answers a different question.
 *
 * "NO REMINDER SENT" is its own state, printed in words. A null
 * `picks_reminded_at` means the wave never went out, and a 0% would blame the
 * reminder for a message nobody sent.
 */
class ReminderLift extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 12;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $saturday = PickemWindow::saturday($this->pageFilters ?? []);
        $contests = collect(app(LiveState::class)->build($saturday, names: false)['contests']);

        $sent = $contests->filter(fn (array $row): bool => $row['picks_reminded_at'] !== null);
        $measured = $sent->filter(fn (array $row): bool => $row['reminder_lift'] !== null);

        return [
            Stat::make('Waves sent', number_format($sent->count()))
                ->description($sent->isEmpty()
                    ? 'no reminder sent for this Saturday'
                    : 'of '.$contests->count().' slates')
                ->descriptionIcon('heroicon-m-megaphone')
                ->color($sent->isEmpty() ? 'gray' : 'info'),

            Stat::make(
                'Reminder lift',
                $measured->isEmpty()
                    ? 'no data'
                    : round($measured->avg('reminder_lift') * 100).'%',
            )
                ->description($measured->isEmpty()
                    ? ($sent->isEmpty()
                        ? 'no reminder sent'
                        : 'Nobody was left to move when the wave went out')
                    : 'of the members who had not entered when the wave went out')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color($measured->isEmpty() ? 'gray' : 'success'),

            Stat::make(
                'Late picks',
                $this->lateShare($contests) === null
                    ? 'no data'
                    : round($this->lateShare($contests) * 100).'%',
            )
                ->description($this->lateShare($contests) === null
                    ? 'No picks on this Saturday yet'
                    : 'made inside the last-call window')
                ->descriptionIcon('heroicon-m-clock')
                ->color($this->lateShare($contests) === null ? 'gray' : 'warning'),
        ];
    }

    /** @param  Collection<int, array<string, mixed>>  $contests */
    private function lateShare($contests): ?float
    {
        $measured = $contests->filter(fn (array $row): bool => $row['late_share'] !== null);

        return $measured->isEmpty() ? null : (float) $measured->avg('late_share');
    }
}
