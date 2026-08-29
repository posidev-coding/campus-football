<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditTeam extends EditRecord
{
    protected static string $resource = TeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),

            // No delete: a team is ESPN's row, and deleting one cascades
            // through follows, standings and rankings for a record the next
            // sync would put straight back.
        ];
    }
}
