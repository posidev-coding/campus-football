<?php

namespace App\Filament\Resources\Seasons\Pages;

use App\Filament\Resources\Seasons\SeasonResource;
use App\Models\Season;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewSeason extends ViewRecord
{
    protected static string $resource = SeasonResource::class;

    public function getHeading(): Htmlable
    {
        /** @var Season $record */
        $record = $this->getRecord();

        return view('filament.partials.record-heading', [
            'title' => (string) $record->year,
            'badges' => [[
                'label' => SeasonResource::phaseLabel($record->type),
                'color' => $record->type === Season::REGULAR ? 'info' : 'gray',
            ]],
            'meta' => array_values(array_filter([
                $record->name === null ? null : ['icon' => 'heroicon-o-tag', 'label' => $record->name],
                $record->start_date === null || $record->end_date === null
                    ? null
                    : [
                        'icon' => 'heroicon-o-calendar',
                        'label' => $record->start_date->format('M j').' – '.$record->end_date->format('M j, Y'),
                    ],
            ])),
        ]);
    }

    public function getSubheading(): ?string
    {
        return null;
    }
}
