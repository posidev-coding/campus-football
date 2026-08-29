<?php

namespace App\Filament\Resources\Groups\RelationManagers;

use App\Enums\ContestMode;
use App\Filament\Resources\Contests\ContestResource;
use App\Models\Contest;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The seasons this group has played. One contest per group per season — the
 * schema carries a unique on the pair.
 *
 * Rows link out to the Contest resource, which has no navigation entry of its
 * own: a contest is always reached through the group that plays it.
 */
class ContestsRelationManager extends RelationManager
{
    protected static string $relationship = 'contests';

    protected static ?string $title = 'Contests';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('season_year')->label('Season')->sortable(),
                TextColumn::make('mode')->badge()
                    ->formatStateUsing(fn (ContestMode $state): string => $state->label()),
                TextColumn::make('slates_count')->label('Slates')->counts('slates'),
                TextColumn::make('mode_changed_at')->label('Mode changed')->since()
                    ->color('gray')->placeholder('Never'),
            ])
            ->defaultSort('season_year', 'desc')
            ->recordUrl(fn (Contest $record): string => ContestResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No contest opened yet')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
