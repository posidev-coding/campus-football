<?php

namespace App\Filament\Resources\Games\Widgets;

use App\Models\Game;
use App\Models\GameOdd;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class GameStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    public ?Game $record = null;

    protected function getStats(): array
    {
        return [
            $this->attendance(),
            $this->closingLine(),
            $this->winProbability(),
        ];
    }

    private function attendance(): Stat
    {
        // Null means ESPN never reported it, which is common for a game that
        // has not been played. Never rendered as 0 — an empty stadium is a
        // real and very different claim.
        return $this->record->attendance === null
            ? Stat::make('Attendance', 'Not reported')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('gray')
            : Stat::make('Attendance', number_format($this->record->attendance))
                ->descriptionIcon('heroicon-m-user-group')
                ->color('gray');
    }

    private function closingLine(): Stat
    {
        $odd = $this->record->odds()
            ->with('favorite')
            ->orderByRaw("field(phase, 'close', 'current', 'open')")
            ->orderByDesc('captured_at')
            ->first();

        if ($odd === null || $odd->spread === null) {
            return Stat::make('Line', 'No line')
                ->description('nothing captured for this game')
                ->descriptionIcon('heroicon-m-scale')
                ->color('gray');
        }

        return Stat::make('Line', ($odd->favorite?->abbreviation ?? '').' '.$odd->spread)
            ->description(($odd->phase === GameOdd::CLOSE ? 'closing' : $odd->phase).' · '.($odd->provider ?? 'unknown book'))
            ->descriptionIcon('heroicon-m-scale')
            ->color('gray');
    }

    private function winProbability(): Stat
    {
        $home = $this->record->home_win_prob;

        // Null means the predictor has not run for this game. A 50% default
        // would be a coin flip presented as a model's answer.
        return $home === null
            ? Stat::make('Win probability', 'Not modeled')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('gray')
            : Stat::make('Win probability', round($home).'% home')
                ->description('ESPN predictor')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('gray');
    }
}
