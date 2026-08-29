<?php

namespace App\Filament\Resources\Teams\Widgets;

use App\Filament\Resources\Teams\TeamResource;
use App\Models\Ranking;
use App\Models\Standing;
use App\Models\Team;
use App\Services\CfbCalendar;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TeamStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    public ?Team $record = null;

    protected function getStats(): array
    {
        $year = app(CfbCalendar::class)->currentYear();

        $followers = $this->record->followers()->count();
        $favorites = $this->record->followers()->wherePivot('position', 1)->count();

        return [
            Stat::make('Followers', number_format($followers))
                ->description($favorites === 0
                    ? 'nobody has this as their number one'
                    : $favorites.' have it as their favorite')
                ->descriptionIcon('heroicon-m-heart')
                ->color('gray'),

            $this->rank($year),
            $this->record($year),

            Stat::make('Header style', $this->record->header_style?->label() ?? 'Auto')
                ->description(TeamResource::describe($this->record))
                ->descriptionIcon('heroicon-m-swatch')
                ->color($this->record->header_style === null ? 'gray' : 'info'),
        ];
    }

    /**
     * The latest AP rank, or the plain fact of being unranked.
     *
     * Unranked is NOT rank 26 or 0 — it is no answer, and a number invented
     * here would sort and read as a real one.
     */
    private function rank(int $year): Stat
    {
        $ranking = Ranking::query()
            ->where('team_id', $this->record->id)
            ->whereHas('season', fn ($query) => $query->where('year', $year))
            ->where('poll', 'AP Top 25')
            ->latest('week_id')
            ->first();

        return $ranking === null
            ? Stat::make('AP rank', 'Unranked')
                ->description('season '.$year)
                ->descriptionIcon('heroicon-m-list-bullet')
                ->color('gray')
            : Stat::make('AP rank', '#'.$ranking->rank)
                ->description($ranking->record ?? 'season '.$year)
                ->descriptionIcon('heroicon-m-list-bullet')
                ->color('warning');
    }

    private function record(int $year): Stat
    {
        $standing = Standing::query()
            ->where('team_id', $this->record->id)
            ->where('season_year', $year)
            ->fromEspn()
            ->first();

        // Null means the standings have not synced for this team yet. Never
        // substituted with 0-0, which is a real record somebody could believe.
        return $standing === null
            ? Stat::make('Record', 'Not synced')
                ->description('season '.$year)
                ->descriptionIcon('heroicon-m-trophy')
                ->color('gray')
            : Stat::make('Record', $standing->overallRecord())
                ->description($standing->conferenceRecord().' in conference')
                ->descriptionIcon('heroicon-m-trophy')
                ->color('gray');
    }
}
