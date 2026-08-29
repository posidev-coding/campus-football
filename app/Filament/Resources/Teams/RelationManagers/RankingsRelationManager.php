<?php

namespace App\Filament\Resources\Teams\RelationManagers;

use App\Models\Ranking;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where this team sat in each published poll, latest first.
 *
 * A published ranking never changes retroactively, which is why nothing
 * re-syncs these — the rows are a historical record, not a cache.
 */
class RankingsRelationManager extends RelationManager
{
    protected static string $relationship = 'rankings';

    protected static ?string $title = 'Rankings';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['week', 'season']))
            ->columns([
                TextColumn::make('poll')->badge()->color('gray'),
                TextColumn::make('season.year')->label('Season'),
                TextColumn::make('week.number')->label('Week')->placeholder('—'),
                TextColumn::make('rank')
                    ->label('Rank')
                    ->formatStateUsing(fn (int $state): string => '#'.$state)
                    ->weight('medium'),
                TextColumn::make('previous_rank')
                    ->label('Previous')
                    // Null means they were not in the previous poll at all,
                    // which is different from being ranked last in it.
                    ->formatStateUsing(fn (int $state): string => '#'.$state)
                    ->placeholder('Unranked')
                    ->color('gray'),
                TextColumn::make('record')->placeholder('—'),
                TextColumn::make('first_place_votes')->label('1st-place votes')->placeholder('—')->toggleable(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->latest('week_id'))
            ->emptyStateHeading('Never ranked')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
