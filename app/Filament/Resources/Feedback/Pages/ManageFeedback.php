<?php

namespace App\Filament\Resources\Feedback\Pages;

use App\Filament\Resources\Feedback\FeedbackResource;
use Filament\Resources\Pages\ManageRecords;

class ManageFeedback extends ManageRecords
{
    protected static string $resource = FeedbackResource::class;

    protected function getHeaderActions(): array
    {
        // No CreateAction: a note is something a reader sends from the app,
        // and one an admin typed here would be a note nobody sent.
        return [];
    }
}
