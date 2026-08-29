<?php

namespace App\Filament\Resources\Contests;

use App\Enums\ContestMode;
use App\Filament\Resources\Contests\Pages\ViewContest;
use App\Filament\Resources\Contests\RelationManagers\SlatesRelationManager;
use App\Filament\Resources\Groups\GroupResource;
use App\Models\Contest;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A contest — the playable thing a group runs for one season.
 *
 * NO navigation entry, deliberately: there is one contest per group per season
 * (the schema carries the unique), so a flat list of them is a list of groups
 * with the names taken off. It is always reached from the group that plays it.
 *
 * View-only. `mode` and `settings` are not editable here on purpose — changing
 * a mode mid-season rewrites what published slates mean, and `ChangeGroupMode`
 * is the flow that knows it.
 */
class ContestResource extends Resource
{
    protected static ?string $model = Contest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static bool $shouldRegisterNavigation = false;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The contest')->columns(2)->schema([
                TextEntry::make('group.name')
                    ->label('Group')
                    ->url(fn (Contest $record): ?string => $record->group === null
                        ? null
                        : GroupResource::getUrl('view', ['record' => $record->group])),
                TextEntry::make('season_year')->label('Season'),
                TextEntry::make('mode')->badge()
                    ->formatStateUsing(fn (ContestMode $state): string => $state->label())
                    // Sized from the RECORD: a contest's frozen slate_size is
                    // what it actually deals, and the admin reading this row
                    // is the person who has to trust the number.
                    ->helperText(fn (Contest $record): string => $record->mode->blurb(
                        $record->mode->engine($record->settings)->slateSize(),
                    )),
                TextEntry::make('mode_changed_at')->label('Mode changed')->dateTime()->placeholder('Never'),
                TextEntry::make('settings')
                    ->label('Settings')
                    ->columnSpanFull()
                    ->placeholder('Defaults')
                    /*
                     * `state()`, not `formatStateUsing()`. `settings` carries
                     * an array cast, and Filament renders an array state as a
                     * LIST — it calls the formatter once per ELEMENT. Collapse
                     * it to a string before Filament ever sees the array.
                     */
                    ->state(fn (Contest $record): ?string => $record->settings === null
                        ? null
                        : json_encode($record->settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                    ->fontFamily('mono')
                    ->size('xs'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        // Never listed on its own — see the class docblock. The table exists
        // because Filament wants one; the resource is reached record-first.
        return $table->columns([]);
    }

    public static function getRelations(): array
    {
        return [
            SlatesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewContest::route('/{record}'),
        ];
    }

    /**
     * There is no index page to go back to, so "up" from a contest is the
     * list of groups — which is where you would have come from.
     *
     * Without this override Filament throws a LogicException while rendering
     * the view page's own breadcrumbs, so a record-only resource is not
     * complete until this is answered.
     *
     * @param  array<mixed>  $parameters
     */
    public static function getIndexUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false): string
    {
        return GroupResource::getUrl('index', $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters);
    }
}
