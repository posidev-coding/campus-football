<?php

namespace App\Filament\Resources\Conferences\Pages;

use App\Filament\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewConference extends ViewRecord
{
    protected static string $resource = ConferenceResource::class;

    public function getHeading(): Htmlable
    {
        /** @var Conference $record */
        $record = $this->getRecord();

        return view('filament.partials.record-heading', [
            'image' => $record->logo,
            'title' => $record->name,
            'badges' => [[
                'label' => $record->is_conference ? 'Conference' : 'Grouping',
                'color' => $record->is_conference ? 'info' : 'gray',
            ]],
            'meta' => array_values(array_filter([
                $record->short_name === null
                    ? null
                    : ['icon' => 'heroicon-o-tag', 'label' => $record->short_name],
                $record->abbreviation === null
                    ? null
                    : ['icon' => 'heroicon-o-link', 'label' => $record->abbreviation],
            ])),
        ]);
    }

    public function getSubheading(): ?string
    {
        return null;
    }
}
