<?php

namespace App\Filament\Resources\Venues;

use App\Filament\Resources\Venues\Pages\ManageVenues;
use App\Models\Venue;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Stadiums. ESPN's rows, ESPN's ids, read-only. */
class VenueResource extends Resource
{
    protected static ?string $model = Venue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The venue')->columns(2)->schema([
                ImageEntry::make('image_url')->label('')->height(120)->columnSpanFull(),
                TextEntry::make('name')->weight('bold'),
                TextEntry::make('place')
                    ->label('Where')
                    ->state(fn (Venue $record): ?string => $record->place())
                    ->placeholder('—'),
                TextEntry::make('capacity')
                    ->label('Capacity')
                    // Null means ESPN never told us, not an empty stadium.
                    ->formatStateUsing(fn (int $state): string => number_format($state))
                    ->placeholder('Not reported'),
                TextEntry::make('indoor')->label('Roof')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Indoor' : 'Open air')
                    ->color('gray'),
                TextEntry::make('grass')->label('Surface')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Grass' : 'Turf')
                    ->color('gray'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('place')
                    ->label('Where')
                    ->state(fn (Venue $record): ?string => $record->place())
                    ->placeholder('—'),
                TextColumn::make('capacity')
                    ->sortable()
                    ->placeholder('Not reported')
                    ->formatStateUsing(fn (int $state): string => number_format($state)),
                IconColumn::make('indoor')->boolean()->label('Indoor'),
                IconColumn::make('grass')->boolean()->label('Grass'),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('indoor')->label('Indoor'),
                TernaryFilter::make('grass')->label('Grass'),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([])
            ->emptyStateHeading('No venues synced');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageVenues::route('/'),
        ];
    }
}
