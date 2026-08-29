<?php

namespace App\Filament\Resources\Athletes\Pages;

use App\Filament\Resources\Athletes\AthleteResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAthletes extends ManageRecords
{
    protected static string $resource = AthleteResource::class;

    protected function getHeaderActions(): array
    {
        // ESPN owns the athlete rows and their non-incrementing ids.
        return [];
    }
}
