<?php

namespace App\Filament\Resources\Games\RelationManagers;

use App\Models\Article;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** News ESPN attached to this game. */
class ArticlesRelationManager extends RelationManager
{
    protected static string $relationship = 'articles';

    protected static ?string $title = 'News';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('headline')->wrap()->weight('medium'),
                TextColumn::make('published_at')->label('Published')->since()->color('gray')->placeholder('—'),
                TextColumn::make('url')
                    ->label('Source')
                    ->placeholder('—')
                    ->url(fn (Article $record): ?string => $record->url)
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('published_at', 'desc')
            ->emptyStateHeading('No news attached')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
