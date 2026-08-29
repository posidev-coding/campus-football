<?php

namespace App\Filament\Resources\Games\RelationManagers;

use App\Models\GameOdd;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The line, at each phase it was captured.
 *
 * `open`, `current` and `close` are three different questions, and a slate
 * freezes whichever one was current at publish — so the phase column is what
 * makes a disagreement with a frozen spread legible.
 */
class OddsRelationManager extends RelationManager
{
    protected static string $relationship = 'odds';

    protected static ?string $title = 'Odds';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('favorite'))
            ->columns([
                TextColumn::make('phase')->badge()
                    ->color(fn (?string $state): string => $state === GameOdd::CLOSE ? 'info' : 'gray'),
                TextColumn::make('provider')->placeholder('—'),
                TextColumn::make('favorite.abbreviation')->label('Favorite')->placeholder('—'),
                TextColumn::make('spread')->placeholder('—'),
                TextColumn::make('over_under')->label('O/U')->placeholder('—'),
                TextColumn::make('moneyline_home')->label('ML home')->placeholder('—')->toggleable(),
                TextColumn::make('moneyline_away')->label('ML away')->placeholder('—')->toggleable(),
                TextColumn::make('captured_at')->label('Captured')->dateTime()->color('gray')->placeholder('—'),
            ])
            ->defaultSort('captured_at', 'desc')
            ->emptyStateHeading('No line captured')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
