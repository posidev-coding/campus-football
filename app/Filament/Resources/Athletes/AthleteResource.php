<?php

namespace App\Filament\Resources\Athletes;

use App\Filament\Resources\Athletes\Pages\ManageAthletes;
use App\Models\Athlete;
use App\Models\AthleteTeamSeason;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Athletes — 35,000 rows, which is what shapes every decision here.
 *
 * A player's team is a fact about a SEASON: there is deliberately no
 * `athletes.team_id` and no `team()` relation. The current team therefore
 * comes through `latestSeason` and its team, and BOTH are eager-loaded in the
 * table query. At this row count a lazy load is the N+1 that takes the page
 * down, and lazy loading is disabled in production so it is a 500 rather than
 * a slow page — a query-ceiling test holds it.
 *
 * Table-first with a modal view: a full record page for a table this size buys
 * nothing a modal does not.
 */
class AthleteResource extends Resource
{
    protected static ?string $model = Athlete::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUser;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'display_name';

    /**
     * Opted OUT of global search deliberately.
     *
     * A contains-LIKE over 35,000 rows runs on every keystroke of the panel's
     * global search, and the product's own search solves this with prefix
     * matching that rides the btree index (`#[SearchUsingPrefix]` on the
     * model). The panel's table search below is scoped and intentional; a
     * global one would be neither.
     */
    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        // ESPN owns every column.
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The player')->columns(2)->schema([
                ImageEntry::make('headshot_url')->label('')->height(64)->circular(),
                TextEntry::make('display_name')->label('Name')->weight('bold'),
                TextEntry::make('display_height')->label('Height')->placeholder('—'),
                TextEntry::make('display_weight')->label('Weight')->placeholder('—'),
                TextEntry::make('hometown')
                    ->label('Hometown')
                    ->state(fn (Athlete $record): ?string => $record->hometown())
                    ->placeholder('—'),
                TextEntry::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Departed')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextEntry::make('id')->label('ESPN id')->fontFamily('mono')->size('xs')
                    ->helperText('Athletes route by id, not slug — 326 slugs collide.'),
            ]),

            Section::make('Seasons')
                ->description('A player\'s team is a fact about a season, which is why there is no single team here.')
                ->schema([
                    RepeatableEntry::make('seasons')
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('Season'),
                            TableColumn::make('Team'),
                            TableColumn::make('Jersey'),
                            TableColumn::make('Position'),
                            TableColumn::make('Class'),
                        ])
                        ->schema([
                            TextEntry::make('season_year'),
                            TextEntry::make('team.display_name')->placeholder('—'),
                            TextEntry::make('jersey')->placeholder('—'),
                            TextEntry::make('position_group')->placeholder('—'),
                            TextEntry::make('experience_class')->placeholder('—'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            /*
             * The N+1 trap this resource exists around. `latestSeason.team` is
             * two hops and 35,000 rows — unnamed here, every row on the page
             * fires its own pair of queries, and with lazy loading disabled it
             * is a 500 rather than a slow page. A ceiling test holds it.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('latestSeason.team'))
            ->columns([
                ImageColumn::make('headshot_url')->label('')->circular()->imageSize(28),
                TextColumn::make('display_name')->label('Name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('team')
                    ->label('Team')
                    ->placeholder('Unassigned')
                    ->state(fn (Athlete $record): ?string => $record->latestSeason?->team?->display_name),
                TextColumn::make('season')
                    ->label('Season')
                    ->placeholder('—')
                    ->state(fn (Athlete $record): ?int => $record->latestSeason?->season_year),
                TextColumn::make('position')
                    ->label('Position')
                    ->badge()
                    ->placeholder('—')
                    ->state(fn (Athlete $record): ?string => $record->latestSeason?->position_group),
                TextColumn::make('jersey')
                    ->label('#')
                    ->placeholder('—')
                    ->state(fn (Athlete $record): ?string => $record->latestSeason?->jersey),
                TextColumn::make('display_height')->label('Ht')->placeholder('—')->toggleable(),
                TextColumn::make('display_weight')->label('Wt')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('display_name')
            ->filters([
                SelectFilter::make('position_group')
                    ->label('Position')
                    ->options(fn (): array => AthleteTeamSeason::query()
                        ->whereNotNull('position_group')
                        ->distinct()
                        ->orderBy('position_group')
                        ->pluck('position_group', 'position_group')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $group): Builder => $query->whereHas(
                            'seasons',
                            fn (Builder $q): Builder => $q->where('position_group', $group),
                        ),
                    )),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAthletes::route('/'),
        ];
    }
}
