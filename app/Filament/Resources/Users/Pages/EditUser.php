<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),

            // No DeleteAction here. Deleting an account rides
            // `App\Actions\DeleteUser`, which refuses self-deletion and hand
            // deletes the two morph tables that have no foreign key — the
            // stock action would leave both orphaned. It lives on the view
            // page, where the modal can enumerate the cascade.
        ];
    }
}
