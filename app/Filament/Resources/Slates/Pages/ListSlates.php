<?php

namespace App\Filament\Resources\Slates\Pages;

use App\Filament\Resources\Slates\SlateResource;
use Filament\Resources\Pages\ListRecords;

class ListSlates extends ListRecords
{
    protected static string $resource = SlateResource::class;

    protected function getHeaderActions(): array
    {
        // No create. A slate is published by PublishSlate (or the hourly
        // AutoPublishStandardSlate sweep), which validates the lineup and
        // freezes every line — a blank row here would be a slate nobody
        // could play.
        return [];
    }
}
