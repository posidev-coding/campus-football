<?php

namespace App\Filament\Resources\Teams\RelationManagers;

use App\Enums\StandingSource;
use App\Models\Standing;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Season by season, and from WHICH source.
 *
 * The source badge is not decoration: ESPN's own standings and our computed
 * ones can disagree, and `diverged_at` is how that disagreement is recorded
 * rather than silently resolved.
 */
class StandingsRelationManager extends RelationManager
{
    protected static string $relationship = 'standings';

    protected static ?string $title = 'Standings';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('season_year')->label('Season')->sortable(),
                TextColumn::make('source')->badge()
                    ->formatStateUsing(fn (StandingSource $state): string => $state === StandingSource::Espn
                        ? 'ESPN'
                        : 'Computed')
                    ->color(fn (StandingSource $state): string => $state === StandingSource::Espn ? 'info' : 'gray'),
                TextColumn::make('overall')
                    ->label('Overall')
                    ->state(fn (Standing $record): string => $record->overallRecord()),
                TextColumn::make('conference')
                    ->label('Conference')
                    ->state(fn (Standing $record): string => $record->conferenceRecord()),
                TextColumn::make('points_for')->label('PF')->placeholder('—')->toggleable(),
                TextColumn::make('points_against')->label('PA')->placeholder('—')->toggleable(),
                TextColumn::make('diverged_at')
                    ->label('Diverged')
                    ->since()
                    ->color('danger')
                    ->placeholder('Agrees'),
            ])
            ->defaultSort('season_year', 'desc')
            ->emptyStateHeading('No standings synced')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
