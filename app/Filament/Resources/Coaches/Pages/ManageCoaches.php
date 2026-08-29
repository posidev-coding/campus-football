<?php

namespace App\Filament\Resources\Coaches\Pages;

use App\Filament\Resources\Coaches\CoachResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCoaches extends ManageRecords
{
    protected static string $resource = CoachResource::class;

    protected function getHeaderActions(): array
    {
        // ESPN owns the coach rows and their non-incrementing ids.
        return [];
    }
}
