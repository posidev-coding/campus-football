<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use Filament\Resources\Pages\ManageRecords;

class ManageArticles extends ManageRecords
{
    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        // Articles arrive from the news sync; nobody writes one here.
        return [];
    }
}
