<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        /*
         * No create. An account is made by REGISTERING — a hand-made row skips
         * password hashing rules, the welcome mail, the handle validation and
         * the whole onboarding moment.
         *
         * Dropping the `create` PAGE from the resource is not enough on its
         * own: the scaffolded header action still renders a "New user" button
         * that leads nowhere, and a page-level assertion does not see it.
         */
        return [];
    }
}
