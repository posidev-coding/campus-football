<?php

namespace App\Filament\Resources\Slates\RelationManagers;

use App\Models\SlateGame;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The games on this slate, in the order they are played on the screen.
 */
class GamesRelationManager extends RelationManager
{
    protected static string $relationship = 'games';

    protected static ?string $title = 'Games';

    public function table(Table $table): Table
    {
        return $table
            // The matchup column reads the game; lazy loading is off, so this
            // is not optional.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['game', 'favorite']))
            ->columns([
                TextColumn::make('position')->label('#')->sortable(),
                TextColumn::make('matchup')
                    ->state(fn (SlateGame $record): ?string => $record->game?->short_name ?? $record->game?->name)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('tier')->badge()->placeholder('—')
                    ->tooltip('Tiered and Woodshed slates weight games; Shotgun does not.'),
                TextColumn::make('favorite.abbreviation')->label('Favorite')->placeholder('—'),
                TextColumn::make('spread')
                    ->label('Line')
                    ->placeholder('—')
                    /*
                     * `spread` is FROZEN at publish; `market_spread` is what
                     * the book said at the same moment. They are allowed to
                     * differ — a commissioner can set a line by hand — and the
                     * warning color is how that shows without reading two
                     * columns side by side.
                     */
                    ->color(fn (SlateGame $record): string => $record->market_spread !== null
                        && $record->spread !== null
                        && abs($record->spread - $record->market_spread) > 0.01
                            ? 'warning'
                            : 'gray')
                    ->tooltip(fn (SlateGame $record): ?string => $record->market_spread === null
                        ? null
                        : 'Market said '.$record->market_spread),
                TextColumn::make('market_spread')->label('Market')->placeholder('—')->toggleable(),
                TextColumn::make('picks_count')->label('Picks')->counts('picks'),
                TextColumn::make('quality')
                    ->label('Quality')
                    ->placeholder('Not scored')
                    ->toggleable()
                    ->tooltip('The publish-time snapshot. Null means it could not be scored, never zero.'),
            ])
            ->defaultSort('position')
            ->emptyStateHeading('No games on this slate')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
