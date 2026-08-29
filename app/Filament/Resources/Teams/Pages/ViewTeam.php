<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Teams\Widgets\TeamStats;
use App\Models\Team;
use App\Services\CfbCalendar;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewTeam extends ViewRecord
{
    protected static string $resource = TeamResource::class;

    public function getHeading(): Htmlable
    {
        /** @var Team $record */
        $record = $this->getRecord();

        // Membership is season-scoped — there is no teams.conference_id to
        // read, and the answer depends on which season you ask about.
        $season = $record->seasonFor(app(CfbCalendar::class)->currentYear())->with('conference')->first();

        return view('filament.partials.record-heading', [
            'image' => $record->logo,
            'title' => $record->display_name,
            'badges' => array_values(array_filter([
                $season?->conference?->short_name === null
                    ? null
                    : ['label' => $season->conference->short_name, 'color' => 'info'],
                $season?->classification === null
                    ? null
                    : ['label' => strtoupper($season->classification), 'color' => 'gray'],
            ])),
            'meta' => array_values(array_filter([
                $record->abbreviation === null
                    ? null
                    : ['icon' => 'heroicon-o-hashtag', 'label' => $record->abbreviation],
                $record->location === null
                    ? null
                    : ['icon' => 'heroicon-o-map-pin', 'label' => $record->location],
                ['icon' => 'heroicon-o-swatch', 'label' => TeamResource::describe($record)],
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
            TeamStats::make(['record' => $this->getRecord()]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
