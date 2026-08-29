<?php

namespace App\Filament\Resources\Seasons\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * ESPN's weeks for this season phase.
 *
 * A week is a RANGE, not a Saturday — one of them can span two Saturdays,
 * which is why a slate is anchored to its own `saturday` column rather than to
 * `week_id`.
 */
class WeeksRelationManager extends RelationManager
{
    protected static string $relationship = 'weeks';

    protected static ?string $title = 'Weeks';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('#')->sortable(),
                TextColumn::make('name')->placeholder('—'),
                TextColumn::make('start_date')->label('Starts')->date('M j, Y')->placeholder('—'),
                TextColumn::make('end_date')->label('Ends')->date('M j, Y')->placeholder('—'),
            ])
            ->defaultSort('number')
            ->emptyStateHeading('No weeks synced')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
