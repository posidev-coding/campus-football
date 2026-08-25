<?php

namespace App\Filament\Resources\Teams;

use App\Enums\HeaderStyle;
use App\Filament\Resources\Teams\Pages\ManageTeams;
use App\Models\Team;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Team branding curation — nothing else.
 *
 * The one editable field is `header_style`, the human override for the
 * TeamPalette ladder: the algorithm gets the league right, and this exists
 * for the last few percent of taste that cannot be computed. Everything else
 * about a team is ESPN's, arrives through the sync, and is not editable —
 * a hand-edited display name would be silently overwritten within a week.
 */
class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Team Branding';

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
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo')->label('')->imageSize(28),
                TextColumn::make('display_name')->label('Team')->searchable()->sortable(),
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
            ])
            ->defaultSort('display_name')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTeams::route('/'),
        ];
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
    private static function describe(Team $team): string
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
