<?php

namespace App\Filament\Resources\ConversationPosts\Pages;

use App\Filament\Resources\ConversationPosts\ConversationPostResource;
use Filament\Resources\Pages\ManageRecords;

class ManageConversationPosts extends ManageRecords
{
    protected static string $resource = ConversationPostResource::class;

    protected function getHeaderActions(): array
    {
        // A post is written by a person in the product. Moderation deletes, it never authors.
        return [];
    }
}
