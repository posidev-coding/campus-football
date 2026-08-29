<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\Pick;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every call this person has made.
 *
 * DELIBERATELY NOT `Pick::visibleTo()`. That scope hides a pick until its game
 * kicks off, which is the whole integrity model for readers — you cannot see
 * what your rival took until it stops mattering. This is an admin audit
 * surface and it bypasses that on purpose; the alternative is a support
 * conversation about a missing pick that an admin cannot see either.
 */
class PicksRelationManager extends RelationManager
{
    protected static string $relationship = 'picks';

    protected static ?string $title = 'Picks';

    public function table(Table $table): Table
    {
        return $table
            // Lazy loading is off, so every relation the columns below read
            // must be named here or the page 500s.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['slateGame.slate', 'slateGame.game', 'pickedTeam']))
            ->columns([
                TextColumn::make('saturday')
                    ->label('Saturday')
                    ->state(fn (Pick $record) => $record->slateGame?->slate?->saturday)
                    ->date('M j, Y')
                    ->placeholder('—'),
                TextColumn::make('matchup')
                    ->state(fn (Pick $record): ?string => $record->slateGame?->game?->short_name
                        ?? $record->slateGame?->game?->name)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('picked')
                    ->label('Took')
                    ->state(fn (Pick $record): ?string => $record->pickedTeam?->abbreviation
                        ?? $record->pickedTeam?->display_name)
                    ->weight('medium')
                    ->placeholder('—'),
                // The stored WAGER, not the kickoff clock — two different
                // "locked"s, kept apart on purpose.
                IconColumn::make('locked')
                    ->label('Lock')
                    ->boolean()
                    ->tooltip('The Woodshed Lock wager: +6 right, −4 wrong.'),
                TextColumn::make('result')
                    ->badge()
                    ->placeholder('Ungraded')
                    ->color(fn (?string $state): string => match ($state) {
                        Pick::WIN => 'success',
                        Pick::LOSS => 'danger',
                        Pick::PUSH => 'gray',
                        default => 'gray',
                    })
                    // An enum-ish string column sorts alphabetically otherwise,
                    // which puts loss above push above win and reads as a bug.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw("field(result, 'win', 'loss', 'push') ".($direction === 'desc' ? 'desc' : 'asc'))),
                TextColumn::make('points')
                    ->placeholder('—')
                    // Points are SIGNED — a backfired Woodshed Lock is a real
                    // −4, and printing it as 0 was a shipped bug once.
                    ->formatStateUsing(fn (int $state): string => sprintf('%+d', $state))
                    ->color(fn (int $state): string => match (true) {
                        $state > 0 => 'success',
                        $state < 0 => 'danger',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No picks yet')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
