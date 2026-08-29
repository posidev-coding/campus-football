<?php

namespace App\Filament\Resources\Groups\Pages;

use App\Filament\Resources\Groups\GroupResource;
use Filament\Resources\Pages\ListRecords;

class ListGroups extends ListRecords
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        // No create. CreateGroup seats the maker as commissioner, mints the
        // invite code and opens the season's contest; a hand-made row is a
        // group nobody runs.
        return [];
    }
}
