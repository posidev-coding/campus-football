<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\Team;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The teams this person follows, in the order THEY chose.
 *
 * READ-ONLY, and no reorder handle — deliberately. This order is the reader's
 * own curation: it drives their Home swipe order, their scoreboard float
 * order and whose news leads for them. An admin dragging it would silently
 * rearrange somebody's home screen, and position 1 is their favorite team.
 */
class FollowedTeamsRelationManager extends RelationManager
{
    protected static string $relationship = 'followedTeams';

    protected static ?string $title = 'Followed teams';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo')->label('')->imageSize(24),
                TextColumn::make('display_name')->label('Team'),
                TextColumn::make('location')->label('Place')->color('gray'),
                TextColumn::make('position')
                    ->label('Order')
                    ->badge()
                    ->state(fn (Team $record): string => (int) $record->pivot->position === 1
                        ? '★ Favorite'
                        : '#'.$record->pivot->position)
                    ->color(fn (Team $record): string => (int) $record->pivot->position === 1 ? 'warning' : 'gray'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Following nobody yet')
            // No create, attach, edit, delete or reorder. See the class
            // docblock — this is somebody else's curation.
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
