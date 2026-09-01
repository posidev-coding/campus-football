<?php

namespace App\Filament\Resources\Users\Widgets;

use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four numbers about one person, above their record.
 *
 * `$isDiscovered = false` keeps it off the dashboard — it needs a record, and
 * a discovered widget is instantiated without one.
 */
class UserStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    public ?User $record = null;

    protected function getStats(): array
    {
        $wallet = $this->record->walletTotals();

        return [
            Stat::make('XP', number_format($wallet['xp']))
                ->descriptionIcon('heroicon-m-bolt')
                ->color('warning'),

            Stat::make('Tallboys', number_format($wallet['credits']))
                ->descriptionIcon('heroicon-m-beaker')
                ->color('gray'),

            $this->picks(),

            $this->groups(),
        ];
    }

    /**
     * Picks made, with a win rate ONLY once something has been graded.
     *
     * An ungraded season is not 0% — it is no answer yet, and a percentage
     * printed over nothing is the same fabrication as a default written where
     * data is missing.
     */
    private function picks(): Stat
    {
        $total = $this->record->picks()->count();
        $graded = $this->record->picks()->whereNotNull('result')->count();
        $wins = $this->record->picks()->where('result', Pick::WIN)->count();

        return Stat::make('Picks', number_format($total))
            ->description($graded === 0
                ? 'nothing graded yet'
                : round($wins / $graded * 100).'% of '.number_format($graded).' graded')
            ->descriptionIcon('heroicon-m-hand-raised')
            ->color('gray');
    }

    private function groups(): Stat
    {
        $groups = $this->record->groups()->count();
        $running = $this->record->groups()->wherePivot('role', GroupMember::COMMISSIONER)->count();

        return Stat::make('Groups', number_format($groups))
            ->description($running === 0 ? 'a member everywhere' : "runs {$running} of them")
            ->descriptionIcon('heroicon-m-user-group')
            ->color('gray');
    }
}
