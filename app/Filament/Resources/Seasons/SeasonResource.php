<?php

namespace App\Filament\Resources\Seasons;

use App\Filament\Resources\Seasons\Pages\ListSeasons;
use App\Filament\Resources\Seasons\Pages\ViewSeason;
use App\Filament\Resources\Seasons\RelationManagers\WeeksRelationManager;
use App\Models\Season;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Seasons, read-only — ESPN's calendar, not ours.
 *
 * Note `(year, type)` is unique, NOT `year`: one college football year is
 * several rows here, one per phase, and the table shows both columns for that
 * reason. Reading "the 2026 season" as one row is how a query silently finds
 * the preseason.
 *
 * The phase names are a label map rather than an invented enum: ESPN's four
 * integers already live as constants on the model, and a second vocabulary
 * would be a place for the two to disagree.
 */
class SeasonResource extends Resource
{
    protected static ?string $model = Season::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'College Football';

    protected static ?int $navigationSort = 4;

    /** @return array<int, string> */
    public static function phases(): array
    {
        return [
            Season::PRESEASON => 'Preseason',
            Season::REGULAR => 'Regular season',
            Season::POSTSEASON => 'Postseason',
            Season::OFFSEASON => 'Offseason',
        ];
    }

    public static function phaseLabel(?int $type): string
    {
        return self::phases()[$type] ?? 'Unknown phase';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The season')->columns(2)->schema([
                TextEntry::make('year'),
                TextEntry::make('type')
                    ->label('Phase')
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => self::phaseLabel($state))
                    ->helperText('(year, type) is unique — one football year is several rows.'),
                TextEntry::make('name')->placeholder('—'),
                TextEntry::make('start_date')->label('Starts')->date()->placeholder('—'),
                TextEntry::make('end_date')->label('Ends')->date()->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')->sortable(),
                TextColumn::make('type')
                    ->label('Phase')
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => self::phaseLabel($state))
                    ->color(fn (?int $state): string => $state === Season::REGULAR ? 'info' : 'gray'),
                TextColumn::make('name')->placeholder('—'),
                TextColumn::make('start_date')->label('Starts')->date('M j, Y')->placeholder('—'),
                TextColumn::make('end_date')->label('Ends')->date('M j, Y')->placeholder('—'),
                TextColumn::make('weeks_count')->label('Weeks')->counts('weeks'),
                TextColumn::make('games_count')->label('Games')->counts('games'),
            ])
            ->defaultSort('year', 'desc')
            ->filters([
                SelectFilter::make('type')->label('Phase')->options(self::phases()),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([])
            ->emptyStateHeading('No seasons synced')
            ->emptyStateDescription('The calendar arrives with cfb:sync.');
    }

    public static function getRelations(): array
    {
        return [
            WeeksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSeasons::route('/'),
            'view' => ViewSeason::route('/{record}'),
        ];
    }
}
