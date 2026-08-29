<?php

namespace App\Filament\Resources\WalletEntries\Pages;

use App\Filament\Resources\WalletEntries\WalletEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWalletEntries extends ManageRecords
{
    protected static string $resource = WalletEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
