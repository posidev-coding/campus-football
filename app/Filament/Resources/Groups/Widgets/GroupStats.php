<?php

namespace App\Filament\Resources\Groups\Widgets;

use App\Models\Group;
use App\Models\Pick;
use App\Models\SlateEntry;
use App\Services\CfbCalendar;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class GroupStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    public ?Group $record = null;

    protected function getStats(): array
    {
        // Never a hardcoded year and never "the latest contest" — a season
        // exists in the database months before anybody plays it.
        $year = app(CfbCalendar::class)->currentYear();

        $members = $this->record->members()->count();
        $cap = $this->record->member_cap;

        return [
            Stat::make('Members', number_format($members))
                ->description($cap === null ? 'uncapped' : $members.' of '.$cap.' seats')
                ->descriptionIcon('heroicon-m-user-group')
                ->color($cap !== null && $members >= $cap ? 'warning' : 'gray'),

            Stat::make('Contests', number_format($this->record->contests()->count()))
                ->description('one per season played')
                ->descriptionIcon('heroicon-m-trophy')
                ->color('gray'),

            Stat::make('Entries this season', number_format($this->entries($year)))
                ->description('season '.$year)
                ->descriptionIcon('heroicon-m-ticket')
                ->color('gray'),

            Stat::make('Picks this season', number_format($this->picks($year)))
                ->description('season '.$year)
                ->descriptionIcon('heroicon-m-hand-raised')
                ->color('gray'),
        ];
    }

    private function entries(int $year): int
    {
        return SlateEntry::query()
            ->whereHas('slate.contest', fn ($query) => $query
                ->where('group_id', $this->record->id)
                ->where('season_year', $year))
            ->count();
    }

    private function picks(int $year): int
    {
        return Pick::query()
            ->whereHas('slateGame.slate.contest', fn ($query) => $query
                ->where('group_id', $this->record->id)
                ->where('season_year', $year))
            ->count();
    }
}
