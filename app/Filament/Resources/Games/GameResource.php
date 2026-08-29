<?php

namespace App\Filament\Resources\Games;

use App\Filament\Resources\Games\Pages\ListGames;
use App\Filament\Resources\Games\Pages\ViewGame;
use App\Filament\Resources\Games\RelationManagers\ArticlesRelationManager;
use App\Filament\Resources\Games\RelationManagers\OddsRelationManager;
use App\Filament\Resources\Games\RelationManagers\ScoringPlaysRelationManager;
use App\Filament\Resources\Games\RelationManagers\TeamStatsRelationManager;
use App\Models\Game;
use App\Models\Season;
use App\Models\Week;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Games, read-only. ESPN owns every column and `games.id` is its own
 * non-incrementing key, so there is nothing here to create or edit.
 *
 * ==> NEVER TOUCH `drives`. <==
 *
 * `game_summaries.drives` was 86% of the database and averages 306 KB per row.
 * It lives in its own `game_drives` table now precisely so that a page like
 * this one cannot pull it by accident — no column, no infolist entry, no eager
 * load, and no `$with` anywhere near this resource may name it. A test asserts
 * that listing games fires zero `game_drives` queries.
 *
 * The matchup column is composed from the DENORMALIZED home/away columns on
 * `games`, which is what keeps the scoreboard join-free — reading it costs no
 * joins here either.
 */
class GameResource extends Resource
{
    protected static ?string $model = Game::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'College Football';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function form(Schema $schema): Schema
    {
        // Read-only: ESPN owns every column.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // `week.season` for the season/week labels. NOTHING that reaches
            // game_drives — see the class docblock.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('week.season'))
            ->columns([
                TextColumn::make('kickoff_at')->label('Kickoff')->dateTime('M j, Y g:i a')->sortable(),
                TextColumn::make('name')
                    ->label('Matchup')
                    ->searchable()
                    ->wrap()
                    ->weight('medium'),
                TextColumn::make('score')
                    ->label('Score')
                    /*
                     * Composed from the denormalized columns — zero joins.
                     *
                     * Gated on hasKickedOff(), NOT on the columns being null:
                     * `games.home_score` is `unsignedTinyInteger` DEFAULT 0
                     * and not nullable, so a scheduled game genuinely stores
                     * 0-0. Rendering that is a real scoreline for a game
                     * nobody has played, which is exactly the class of
                     * fabricated default this codebase refuses everywhere
                     * else. The clock is the only honest signal here.
                     */
                    ->state(fn (Game $record): ?string => $record->hasKickedOff()
                        ? $record->away_score.' – '.$record->home_score
                        : null)
                    ->placeholder('Not played'),
                TextColumn::make('status')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state, Game $record): string => self::statusLabel($record))
                    ->color(fn (Game $record): string => self::statusColor($record)),
                TextColumn::make('week.season.year')->label('Season')->placeholder('—'),
                TextColumn::make('week.number')->label('Week')->placeholder('—'),
                TextColumn::make('broadcasts')
                    ->label('TV')
                    ->placeholder('—')
                    /*
                     * `broadcasts` carries an array cast, and Filament renders
                     * an array state as a LIST — one formatter call per
                     * ELEMENT, with the element. Collapse it to a string here
                     * or the first row with two networks is a TypeError.
                     */
                    ->state(fn (Game $record): ?string => empty($record->broadcasts)
                        ? null
                        : implode(', ', $record->broadcasts))
                    ->toggleable(),
                TextColumn::make('attendance')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('kickoff_at', 'desc')
            ->filters([
                SelectFilter::make('season')
                    ->label('Season')
                    ->options(fn (): array => Season::query()
                        ->orderByDesc('year')
                        ->pluck('year', 'year')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, $year): Builder => $query->whereHas(
                            'season',
                            fn (Builder $q): Builder => $q->where('year', $year),
                        ),
                    )),
                SelectFilter::make('week_id')
                    ->label('Week')
                    ->options(fn (): array => Week::query()
                        ->with('season')
                        ->orderByDesc('start_date')
                        ->limit(40)
                        ->get()
                        ->mapWithKeys(fn (Week $week): array => [
                            $week->id => ($week->season?->year ?? '').' · '.($week->name ?? 'Week '.$week->number),
                        ])
                        ->all()),
                Filter::make('state')
                    ->schema([
                        Select::make('value')
                            ->label('State')
                            ->options([
                                'completed' => 'Final',
                                'in_progress' => 'In progress',
                                'upcoming' => 'Upcoming',
                            ])
                            ->native(false),
                    ])
                    // Straight through the model's own scopes, so the panel
                    // and the product agree on what "in progress" means.
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'completed' => $query->completed(),
                        'in_progress' => $query->inProgress(),
                        'upcoming' => $query->upcoming(),
                        default => $query,
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([ViewAction::make()])
            ->toolbarActions([])
            ->emptyStateHeading('No games synced')
            ->emptyStateDescription('Schedules arrive with cfb:games.');
    }

    public static function getRelations(): array
    {
        return [
            OddsRelationManager::class,
            ScoringPlaysRelationManager::class,
            TeamStatsRelationManager::class,
            ArticlesRelationManager::class,
        ];

        // No drives relation manager, and there never will be one.
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGames::route('/'),
            'view' => ViewGame::route('/{record}'),
        ];
    }

    public static function statusLabel(Game $game): string
    {
        return match (true) {
            $game->completed => 'Final',
            in_array($game->status, ['in', 'halftime', 'end-period'], true) => 'Live',
            default => 'Scheduled',
        };
    }

    public static function statusColor(Game $game): string
    {
        return match (true) {
            $game->completed => 'gray',
            // Live is the one state anybody is looking for on this table.
            in_array($game->status, ['in', 'halftime', 'end-period'], true) => 'danger',
            default => 'info',
        };
    }
}
