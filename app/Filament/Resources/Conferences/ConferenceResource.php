<?php

namespace App\Filament\Resources\Conferences;

use App\Filament\Resources\Conferences\Pages\ListConferences;
use App\Filament\Resources\Conferences\Pages\ViewConference;
use App\Filament\Resources\Conferences\RelationManagers\MembershipsRelationManager;
use App\Models\Conference;
use App\Services\CfbCalendar;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Conferences, read-only — ESPN owns the rows and the ids.
 *
 * The member count is season-scoped, and has to be: Oregon is Pac-12 in 2021
 * and Big Ten in 2025, and 513 teams changed conference between those years.
 * It reads `Conference::memberships()` (unparameterized) with the year in the
 * withCount closure, because `teamSeasons(int $year)` cannot feed withCount —
 * that resolves a relation by calling the method with no arguments.
 */
class ConferenceResource extends Resource
{
    protected static ?string $model = Conference::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'College Football';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The conference')->columns(2)->schema([
                ImageEntry::make('logo')->label('Logo')->height(40),
                TextEntry::make('name'),
                TextEntry::make('short_name')->label('Short name')->placeholder('—')
                    ->helperText('What a person calls it. `abbreviation` is ESPN\'s URL slug and nobody types it.'),
                TextEntry::make('abbreviation')->label('ESPN slug')->fontFamily('mono')->size('xs')->placeholder('—'),
                TextEntry::make('is_conference')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Conference' : 'Grouping')
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $year = app(CfbCalendar::class)->currentYear();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'memberships as members_count' => fn (Builder $q) => $q->where('season_year', $year),
            ]))
            ->columns([
                ImageColumn::make('logo')->label('')->imageSize(24),
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('short_name')->label('Short name')->searchable()->placeholder('—'),
                IconColumn::make('is_conference')->label('Conference')->boolean()
                    ->tooltip('False means a grouping — an ESPN bucket rather than a real conference.'),
                TextColumn::make('members_count')
                    ->label('Members')
                    ->sortable()
                    ->description('season '.$year),
            ])
            ->defaultSort('name')
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [
            MembershipsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConferences::route('/'),
            'view' => ViewConference::route('/{record}'),
        ];

        // No create or edit: ESPN owns the row and the non-incrementing id.
    }
}
