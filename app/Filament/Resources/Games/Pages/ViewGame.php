<?php

namespace App\Filament\Resources\Games\Pages;

use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Widgets\GameStats;
use App\Models\Game;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewGame extends ViewRecord
{
    protected static string $resource = GameResource::class;

    public function getHeading(): Htmlable
    {
        /** @var Game $record */
        $record = $this->getRecord();

        // homeTeam/awayTeam only — nothing here goes near `drives`.
        $record->loadMissing(['homeTeam', 'awayTeam', 'venue', 'week.season']);

        return view('filament.partials.record-heading-matchup', [
            'homeLogo' => $record->homeTeam?->logo,
            'awayLogo' => $record->awayTeam?->logo,
            'homeName' => $record->homeTeam?->abbreviation ?? $record->homeTeam?->display_name ?? 'TBD',
            'awayName' => $record->awayTeam?->abbreviation ?? $record->awayTeam?->display_name ?? 'TBD',
            /*
             * Gated on the clock, not on a null: `home_score` is NOT NULL and
             * defaults to 0, so an unplayed game stores a real-looking 0–0.
             * The partial renders "at" instead when there is no score to show.
             */
            'score' => $record->hasKickedOff()
                ? $record->away_score.' – '.$record->home_score
                : null,
            'badges' => array_values(array_filter([
                ['label' => GameResource::statusLabel($record), 'color' => GameResource::statusColor($record)],
                $record->neutral_site ? ['label' => 'Neutral site', 'color' => 'gray'] : null,
                $record->conference_game ? ['label' => 'Conference', 'color' => 'gray'] : null,
                $record->note === null ? null : ['label' => $record->note, 'color' => 'warning'],
            ])),
            'meta' => array_values(array_filter([
                $record->kickoff_at === null
                    ? null
                    : ['icon' => 'heroicon-o-clock', 'label' => $record->kickoff_at->format('D M j, Y g:i a')],
                $record->venue === null
                    ? null
                    : ['icon' => 'heroicon-o-map-pin', 'label' => $record->venue->name],
                $record->week === null
                    ? null
                    : ['icon' => 'heroicon-o-hashtag', 'label' => ($record->week->season?->year ?? '').' Week '.$record->week->number],
            ])),
        ]);
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            GameStats::make(['record' => $this->getRecord()]),
        ];
    }
}
