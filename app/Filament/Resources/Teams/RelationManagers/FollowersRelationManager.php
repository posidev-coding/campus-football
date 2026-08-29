<?php

namespace App\Filament\Resources\Teams\RelationManagers;

use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Who follows this team, and which of them call it their favorite.
 *
 * Position 1 is the favorite — there is no favorite_team_id column anywhere,
 * the ORDER is the model.
 */
class FollowersRelationManager extends RelationManager
{
    protected static string $relationship = 'followers';

    protected static ?string $title = 'Followers';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->state(fn (User $record): string => $record->name)
                    ->weight('medium'),
                TextColumn::make('handle')->fontFamily('mono')->size('xs')->placeholder('—'),
                TextColumn::make('position')
                    ->label('Their order')
                    ->badge()
                    ->state(fn (User $record): string => (int) $record->pivot->position === 1
                        ? '★ Favorite'
                        : '#'.$record->pivot->position)
                    ->color(fn (User $record): string => (int) $record->pivot->position === 1 ? 'warning' : 'gray'),
            ])
            ->emptyStateHeading('Nobody follows this team yet')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
