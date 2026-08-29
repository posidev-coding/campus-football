<?php

namespace App\Filament\Resources\Slates\Widgets;

use App\Filament\Resources\Slates\SlateResource;
use App\Models\Pick;
use App\Models\Slate;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SlateStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    public ?Slate $record = null;

    protected function getStats(): array
    {
        $entries = $this->record->entries()->count();
        $games = $this->record->games()->count();

        $made = Pick::query()
            ->whereHas('slateGame', fn ($query) => $query->where('slate_id', $this->record->id))
            ->count();

        $possible = $entries * $games;

        return [
            Stat::make('Entries', number_format($entries))
                ->descriptionIcon('heroicon-m-ticket')
                ->color('gray'),

            Stat::make('Games', number_format($games))
                ->description('lined and frozen at publish')
                ->descriptionIcon('heroicon-m-rectangle-stack')
                ->color('gray'),

            Stat::make('Picks made', number_format($made))
                // No percentage over a zero denominator: an unentered slate is
                // not 0% complete, it is a slate nobody has opened.
                ->description($possible === 0
                    ? 'nothing to pick against yet'
                    : 'of '.number_format($possible).' possible')
                ->descriptionIcon('heroicon-m-hand-raised')
                ->color('gray'),

            $this->settlement(),
        ];
    }

    private function settlement(): Stat
    {
        return Stat::make('State', SlateResource::statusLabel($this->record->status))
            ->description(match ($this->record->status) {
                Slate::DRAFT => 'not published — nobody can enter yet',
                Slate::PUBLISHED => 'open, or waiting on kickoff',
                // The window between the last whistle and the official-final
                // moment, where an ESPN correction can still move a
                // tiebreaker. No payouts in this state.
                Slate::PRELIM => 'every game final, week not yet official — no payouts',
                Slate::SETTLED => 'settled '.($this->record->settled_at?->diffForHumans() ?? ''),
                default => '',
            })
            ->descriptionIcon('heroicon-m-flag')
            ->color(SlateResource::statusColor($this->record->status));
    }
}
