<?php

namespace App\Filament\Resources\Contests\RelationManagers;

use App\Filament\Resources\Slates\SlateResource;
use App\Models\Slate;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Saturdays this contest has run.
 */
class SlatesRelationManager extends RelationManager
{
    protected static string $relationship = 'slates';

    protected static ?string $title = 'Slates';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('saturday')->date('M j, Y')->sortable(),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => SlateResource::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => SlateResource::statusLabel($state)),
                TextColumn::make('games_count')->label('Games')->counts('games'),
                TextColumn::make('entries_count')->label('Entries')->counts('entries'),
                IconColumn::make('exhibition')->boolean()
                    ->tooltip('A practice slate: graded and paid, never counted.'),
                TextColumn::make('published_at')->label('Published')->since()
                    ->color('gray')->placeholder('Draft'),
            ])
            /*
             * `status` holds a plain string, so an alphabetical sort is
             * draft-prelim-published-settled — which puts prelim before
             * published and reads as a bug in the table rather than a sort.
             * FIELD() puts it in lifecycle order.
             */
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw("field(status, 'draft', 'published', 'prelim', 'settled')")
                ->orderByDesc('saturday'))
            ->recordUrl(fn (Slate $record): string => SlateResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No slates yet')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
