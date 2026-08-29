<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use Filament\Resources\Pages\ListRecords;

class ListTeams extends ListRecords
{
    protected static string $resource = TeamResource::class;

    protected function getHeaderActions(): array
    {
        // No create. `teams.id` is ESPN's own non-incrementing key, so a
        // hand-made team has no id the sync would ever match.
        return [];
    }
}
