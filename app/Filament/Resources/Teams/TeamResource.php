<?php

namespace App\Filament\Resources\Teams;

use App\Enums\HeaderStyle;
use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\Pages\ViewTeam;
use App\Filament\Resources\Teams\RelationManagers\FollowersRelationManager;
use App\Filament\Resources\Teams\RelationManagers\RankingsRelationManager;
use App\Filament\Resources\Teams\RelationManagers\StandingsRelationManager;
use App\Models\Conference;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Services\CfbCalendar;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Teams — the whole record now, with branding as one tab of it.
 *
 * This resource used to be "Team Branding" and nothing else. It still owns
 * that curation, and `header_style` is STILL the only editable field: it is
 * the human override for the TeamPalette ladder, which gets the league right
 * and leaves the last few percent of taste to a person. Everything else about
 * a team is ESPN's, arrives through the sync, and a hand edit dies at the next
 * run — silently, which is the worst way for an edit to fail.
 *
 * Two structural facts. `teams.id` is ESPN's own key and non-incrementing, so
 * there is no Create action. And there is no `teams.conference_id`: membership
 * is season-scoped through `team_seasons`, because Oregon is Pac-12 in 2021
 * and Big Ten in 2025 and 513 teams changed conference between those years.
 */
class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'College Football';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Teams';

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['display_name', 'location', 'abbreviation'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('header_style')
                    ->label('Header style')
                    ->placeholder('Auto — let the palette decide')
                    ->options(collect(HeaderStyle::cases())->mapWithKeys(
                        fn (HeaderStyle $style) => [$style->value => $style->label()],
                    ))
                    ->helperText(
                        'Overrides the computed header on the team page and home cards. '
                        .'Presets only, so every choice stays readable. Light mode only — dark mode is always neutral.'
                    )
                    ->native(false),

                // Nothing else is editable. ESPN owns every other column and
                // overwrites it on the next sync.
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make('Identity & branding')->icon(Heroicon::OutlinedSwatch)->columns(2)->schema([
                    ImageEntry::make('logo')->label('Logo')->height(48),
                    TextEntry::make('display_name')->label('Display name'),
                    TextEntry::make('location')->label('Place')
                        ->helperText('What dense lists say instead of the mascot.'),
                    TextEntry::make('name')->label('Mascot')
                        ->helperText('ESPN puts the mascot in `name`; `nickname` is a short location alias.'),
                    TextEntry::make('abbreviation')->placeholder('—'),
                    TextEntry::make('slug')->fontFamily('mono')->size('xs'),
                    TextEntry::make('primary')
                        ->label('Primary color')
                        ->placeholder('No color')
                        ->state(fn (Team $record): ?string => $record->accentColor()),
                    TextEntry::make('secondary')
                        ->label('Secondary color')
                        ->placeholder('No color')
                        ->state(fn (Team $record): ?string => $record->altAccentColor()),
                    TextEntry::make('header_style')
                        ->label('Header style')
                        ->badge()
                        ->state(fn (Team $record): string => $record->header_style?->label() ?? 'Auto')
                        ->color(fn (Team $record): string => $record->header_style === null ? 'gray' : 'info'),
                    TextEntry::make('renders_as')
                        ->label('Renders as')
                        ->state(fn (Team $record): string => self::describe($record))
                        // Verifying that a color was APPLIED is not verifying
                        // it is READABLE — this is what the ladder actually
                        // chose, computed, never assumed.
                        ->helperText('Computed by TeamPalette. Dark mode is always neutral.'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        // Resolved ONCE for the whole page, not per row.
        $year = app(CfbCalendar::class)->currentYear();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('followers')
                // Season-scoped, and constrained here rather than in the
                // column: an unconstrained load pulls every season a team has
                // ever played, and lazy loading is off so an absent one is a
                // 500 rather than a wrong answer.
                ->with(['seasons' => fn ($q) => $q->where('season_year', $year)->with('conference')]))
            ->columns([
                ImageColumn::make('logo')->label('')->imageSize(28),
                TextColumn::make('display_name')->label('Team')->searchable()->sortable(),
                TextColumn::make('conference')
                    ->label('Conference')
                    ->placeholder('Independent or unplaced')
                    ->state(fn (Team $record): ?string => $record->seasons->first()?->conference?->short_name),
                TextColumn::make('classification')
                    ->label('Class')
                    ->badge()
                    ->placeholder('—')
                    ->state(fn (Team $record): ?string => $record->seasons->first()?->classification),
                ImageColumn::make('primary_swatch')
                    ->label('Primary')
                    ->state(fn (Team $team) => self::swatch($team->accentColor())),
                ImageColumn::make('secondary_swatch')
                    ->label('Secondary')
                    ->state(fn (Team $team) => self::swatch($team->altAccentColor())),
                TextColumn::make('header_style')
                    ->label('Header')
                    ->state(fn (Team $team) => $team->header_style?->label() ?? 'Auto')
                    ->badge()
                    ->color(fn (Team $team) => $team->header_style === null ? 'gray' : 'info'),
                TextColumn::make('resolved')
                    ->label('Renders as')
                    ->state(fn (Team $team) => self::describe($team)),
                TextColumn::make('followers_count')->label('Followers')->sortable(),
            ])
            ->defaultSort('display_name')
            ->filters([
                SelectFilter::make('conference')
                    ->label('Conference')
                    ->options(fn (): array => Conference::query()
                        ->whereNotNull('short_name')
                        ->orderBy('short_name')
                        ->pluck('short_name', 'id')
                        ->all())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, $id): Builder => $query->whereHas(
                            'seasons',
                            fn (Builder $q): Builder => $q
                                ->where('season_year', app(CfbCalendar::class)->currentYear())
                                ->where('conference_id', $id),
                        ),
                    )),
                SelectFilter::make('classification')
                    ->label('Classification')
                    ->options(fn (): array => TeamSeason::query()
                        ->whereNotNull('classification')
                        ->distinct()
                        ->orderBy('classification')
                        ->pluck('classification', 'classification')
                        ->all())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, $class): Builder => $query->whereHas(
                            'seasons',
                            fn (Builder $q): Builder => $q
                                ->where('season_year', app(CfbCalendar::class)->currentYear())
                                ->where('classification', $class),
                        ),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No teams synced')
            ->emptyStateDescription('Reference data arrives with cfb:sync.');
    }

    public static function getRelations(): array
    {
        return [
            FollowersRelationManager::class,
            StandingsRelationManager::class,
            RankingsRelationManager::class,
        ];

        // NO games relation manager. `Team::games()` returns a Builder over a
        // UNION of home and away — home and away are separate denormalized
        // columns, which is what keeps the scoreboard join-free — so it is not
        // an Eloquent relation and a RelationManager cannot take it.
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeams::route('/'),
            'view' => ViewTeam::route('/{record}'),
            'edit' => EditTeam::route('/{record}/edit'),
        ];

        // No create: `teams.id` is ESPN's own non-incrementing key, so a
        // hand-made team has no id the sync would ever match.
    }

    /** A color square as an inline SVG data URI — no asset, no CSS fight. */
    private static function swatch(?string $hex): ?string
    {
        if ($hex === null) {
            return null;
        }

        return 'data:image/svg+xml,'.rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24">'
            .'<rect width="24" height="24" rx="6" fill="'.$hex.'"/></svg>'
        );
    }

    /** "White on #002b5c", so the admin can see what the ladder chose. */
    public static function describe(Team $team): string
    {
        $palette = $team->palette();

        if ($palette === null) {
            return 'No color';
        }

        $text = match (strtolower($palette->text)) {
            '#ffffff' => 'White',
            '#18181b' => 'Dark',
            default => $palette->text,
        };

        return "{$text} on {$palette->surface}";
    }
}
