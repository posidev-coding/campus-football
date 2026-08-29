<?php

namespace App\Filament\Resources\Contests\Pages;

use App\Filament\Resources\Contests\ContestResource;
use App\Models\Contest;
use Filament\Resources\Pages\ViewRecord;

class ViewContest extends ViewRecord
{
    protected static string $resource = ContestResource::class;

    public function getHeading(): string
    {
        /** @var Contest $record */
        $record = $this->getRecord();

        return ($record->group?->name ?? 'Contest').' · '.$record->season_year;
    }

    public function getSubheading(): string
    {
        return $this->getRecord()->mode->label();
    }

    protected function getHeaderActions(): array
    {
        // View only. Mode and settings changes belong to ChangeGroupMode,
        // which knows what a mid-season change does to published slates.
        return [];
    }
}
