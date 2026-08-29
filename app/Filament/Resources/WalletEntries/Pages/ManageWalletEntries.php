<?php

namespace App\Filament\Resources\WalletEntries\Pages;

use App\Filament\Resources\WalletEntries\WalletEntryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageWalletEntries extends ManageRecords
{
    protected static string $resource = WalletEntryResource::class;

    protected function getHeaderActions(): array
    {
        /*
         * NO CreateAction, and this one matters more than most.
         *
         * Filament's create modal writes the row directly, which would walk
         * straight around `GrantWalletEntry` — the single doorway where the
         * idempotency rule lives, and the reason a double-fired event pays
         * nobody twice. The resource's own Grant action is the way in, and it
         * calls the Action.
         */
        return [];
    }
}
