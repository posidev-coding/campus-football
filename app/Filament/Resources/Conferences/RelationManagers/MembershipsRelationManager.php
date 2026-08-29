<?php

namespace App\Filament\Resources\Conferences\RelationManagers;

use App\Services\CfbCalendar;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who is in this conference — for ONE season, always.
 *
 * There is no season-less answer to this question. Membership lives on
 * `team_seasons` precisely because a scalar would be a lie the moment a team
 * moved, and 513 of them moved between 2021 and 2025.
 */
class MembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $title = 'Members';

    public function table(Table $table): Table
    {
        $year = app(CfbCalendar::class)->currentYear();

        return $table
            ->description('Season '.$year.'. Conference membership is season-scoped — this is not a permanent roster.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where('season_year', $year)
                ->with('team'))
            ->columns([
                ImageColumn::make('team.logo')->label('')->imageSize(24),
                TextColumn::make('team.display_name')->label('Team')->placeholder('—'),
                TextColumn::make('team.location')->label('Place')->color('gray')->placeholder('—'),
                TextColumn::make('classification')->badge()->placeholder('—'),
                TextColumn::make('division_id')->label('Division')->placeholder('—')->toggleable(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('team_id'))
            ->emptyStateHeading('No members for this season')
            ->emptyStateDescription('Either the season has not synced, or this conference did not exist that year.')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
