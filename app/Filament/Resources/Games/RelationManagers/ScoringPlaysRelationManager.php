<?php

namespace App\Filament\Resources\Games\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * How the score got where it got, in ESPN's own sequence.
 *
 * This is the cheap half of a box score. The expensive half — drives — lives
 * in its own table and is NEVER loaded from this resource.
 */
class ScoringPlaysRelationManager extends RelationManager
{
    protected static string $relationship = 'scoringPlays';

    protected static ?string $title = 'Scoring';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('team'))
            ->columns([
                TextColumn::make('period')->label('Qtr')->placeholder('—'),
                TextColumn::make('clock')->placeholder('—'),
                TextColumn::make('team.abbreviation')->label('Team')->placeholder('—'),
                TextColumn::make('type')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('text')->label('Play')->wrap()->placeholder('—'),
                TextColumn::make('score')
                    ->label('Score')
                    ->state(fn ($record): string => $record->away_score.' – '.$record->home_score),
            ])
            ->defaultSort('sequence')
            ->emptyStateHeading('No scoring plays synced')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
