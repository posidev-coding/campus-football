<?php

namespace App\Filament\Resources\Venues\Pages;

use App\Filament\Resources\Venues\VenueResource;
use Filament\Resources\Pages\ManageRecords;

class ManageVenues extends ManageRecords
{
    protected static string $resource = VenueResource::class;

    protected function getHeaderActions(): array
    {
        // ESPN owns the venue rows and their non-incrementing ids.
        return [];
    }
}
