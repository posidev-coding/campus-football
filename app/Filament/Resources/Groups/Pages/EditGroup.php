<?php

namespace App\Filament\Resources\Groups\Pages;

use App\Filament\Resources\Groups\GroupResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditGroup extends EditRecord
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),

            // No delete. Deleting a group cascades through its contests,
            // slates, entries and every pick made in it — that is a season
            // being erased, and nothing in the product asks for it.
        ];
    }
}
