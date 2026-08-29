<?php

namespace App\Filament\Resources\Games\RelationManagers;

use App\Models\GameTeamStat;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The team box score, one row per side.
 *
 * `display_stats` is a JSON ARRAY that carries the ORDER, because MySQL JSON
 * does not preserve object key order — the keyed `stats` map comes back
 * reordered, so the array is the one to read for anything a human sees.
 */
class TeamStatsRelationManager extends RelationManager
{
    protected static string $relationship = 'teamStats';

    protected static ?string $title = 'Team stats';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('team'))
            ->columns([
                TextColumn::make('team.display_name')->label('Team')->placeholder('—'),
                TextColumn::make('summary')
                    ->label('Line')
                    ->wrap()
                    ->placeholder('Not synced')
                    /*
                     * `display_stats` has an array cast, so Filament would
                     * render it as a LIST and call the formatter once per
                     * element. Collapsed here before it ever sees the array.
                     */
                    ->state(fn (GameTeamStat $record): ?string => self::line($record)),
            ])
            ->emptyStateHeading('No box score synced')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    /** The first few stats, in the order ESPN sent them. */
    private static function line(GameTeamStat $record): ?string
    {
        $stats = $record->display_stats;

        if (empty($stats)) {
            return null;
        }

        return collect($stats)
            ->take(6)
            ->map(fn ($stat): string => is_array($stat)
                ? (($stat['label'] ?? $stat['name'] ?? '').' '.($stat['displayValue'] ?? $stat['value'] ?? ''))
                : (string) $stat)
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->implode(' · ');
    }
}
