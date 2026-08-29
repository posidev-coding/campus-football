<?php

namespace App\Filament\Resources\Slates\RelationManagers;

use App\Models\SlateEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who played this slate and how they finished.
 *
 * An admin audit surface: it shows entries and totals before results are
 * announced, which the product deliberately does not.
 */
class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Entries';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('user')
                    ->label('Player')
                    ->state(fn (SlateEntry $record): ?string => $record->user?->name)
                    ->placeholder('—')
                    ->weight('medium'),
                TextColumn::make('final_points')
                    ->label('Points')
                    ->placeholder('Not settled')
                    // Points are SIGNED — a backfired Woodshed Lock is a real
                    // −4, and a total can genuinely finish below zero.
                    ->formatStateUsing(fn (int $state): string => sprintf('%+d', $state))
                    ->color(fn (int $state): string => match (true) {
                        $state > 0 => 'success',
                        $state < 0 => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('tiebreaker_total')
                    ->label('Tiebreaker')
                    // Null means they never entered one, and at settlement a
                    // null LOSES to any non-null. Never rendered as 0.
                    ->placeholder('Not entered'),
                IconColumn::make('won')->boolean(),
                IconColumn::make('beat_bear')->label('Beat the Bear')->boolean()
                    ->tooltip('Woodshed only — the Bear does not run in the other modes.'),
            ])
            ->defaultSort('final_points', 'desc')
            ->emptyStateHeading('Nobody entered')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
