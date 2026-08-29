<?php

namespace App\Filament\Resources\Coaches;

use App\Filament\Resources\Coaches\Pages\ManageCoaches;
use App\Models\Coach;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Coaches — the same shape as Athletes, at a fraction of the size.
 *
 * A coach's team is season-scoped for the same reason a player's is, so the
 * current team comes through `latestSeason.team` and is eager-loaded.
 */
class CoachResource extends Resource
{
    protected static ?string $model = Coach::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The coach')->columns(2)->schema([
                ImageEntry::make('headshot_url')->label('')->height(64)->circular(),
                TextEntry::make('display_name')->label('Name')->weight('bold'),
                TextEntry::make('career')
                    ->label('Career record')
                    // Null until the coach sync runs — never a fabricated 0-0.
                    ->state(fn (Coach $record): ?string => $record->careerRecord())
                    ->placeholder('Not synced'),
                TextEntry::make('experience_years')->label('Years coaching')->placeholder('—'),
                TextEntry::make('hometown')
                    ->label('Hometown')
                    ->state(fn (Coach $record): ?string => $record->hometown())
                    ->placeholder('—'),
                TextEntry::make('date_of_birth')->label('Born')->date()->placeholder('—'),
            ]),

            Section::make('Seasons')->schema([
                RepeatableEntry::make('seasons')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Season'),
                        TableColumn::make('Team'),
                        TableColumn::make('Record'),
                    ])
                    ->schema([
                        TextEntry::make('season_year'),
                        TextEntry::make('team.display_name')->placeholder('—'),
                        TextEntry::make('record')
                            ->state(fn ($record): ?string => $record->record())
                            ->placeholder('—'),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('latestSeason.team'))
            ->columns([
                ImageColumn::make('headshot_url')->label('')->circular()->imageSize(28),
                TextColumn::make('display_name')->label('Name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('team')
                    ->label('Team')
                    ->placeholder('Unassigned')
                    ->state(fn (Coach $record): ?string => $record->latestSeason?->team?->display_name),
                TextColumn::make('career')
                    ->label('Career')
                    ->placeholder('Not synced')
                    ->state(fn (Coach $record): ?string => $record->careerRecord()),
                TextColumn::make('experience_years')->label('Years')->placeholder('—')->sortable(),
            ])
            ->defaultSort('display_name')
            ->recordActions([ViewAction::make()])
            ->toolbarActions([])
            ->emptyStateHeading('No coaches synced')
            ->emptyStateDescription('Staffs arrive with cfb:coaches.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCoaches::route('/'),
        ];
    }
}
