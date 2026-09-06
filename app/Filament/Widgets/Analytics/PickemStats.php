<?php

namespace App\Filament\Widgets\Analytics;

use App\Support\LiveState;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The chosen Saturday, in four numbers.
 *
 * Read through {@see LiveState} with `names: false`, which is the same
 * implementation the ops snapshot and the Overview card use — so the panel
 * and the payload cannot report different entry counts for the same slate.
 *
 * PICKS IN is a share with a visible denominator, and it is "no data" rather
 * than 0% when there is no slate: a Saturday with nothing published has no
 * participation, and 0% would read as a Saturday everybody skipped.
 */
class PickemStats extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 12;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $saturday = $this->saturday();
        $state = app(LiveState::class)->build($saturday, names: false);

        $contests = collect($state['contests']);
        $entries = (int) $contests->sum('entries');
        $made = (int) $contests->sum('picks_made');
        $possible = (int) $contests->sum('picks_possible');
        $empty = (int) $contests->sum('entries_empty');

        return [
            Stat::make('Slates', number_format($contests->count()))
                ->description($saturday->format('M j, Y'))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('gray'),

            Stat::make('Entries', number_format($entries))
                ->description($empty.' with no picks in them')
                ->descriptionIcon('heroicon-m-users')
                ->color($empty > 0 ? 'warning' : 'gray'),

            Stat::make('Picks in', $possible > 0 ? round($made / $possible * 100).'%' : 'no data')
                ->description($possible > 0
                    ? number_format($made).' of '.number_format($possible).' possible'
                    : 'No slate published for this Saturday')
                ->descriptionIcon('heroicon-m-hand-raised')
                ->color($possible > 0 ? 'info' : 'gray'),

            Stat::make('Games', number_format($state['games']['in_window']))
                ->description($state['games']['kicked'].' kicked · '.$state['games']['final'].' final')
                ->descriptionIcon('heroicon-m-flag')
                ->color('gray'),
        ];
    }

    protected function saturday(): CarbonImmutable
    {
        return PickemWindow::saturday($this->pageFilters ?? []);
    }
}
