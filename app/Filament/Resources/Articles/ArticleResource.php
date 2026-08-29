<?php

namespace App\Filament\Resources\Articles;

use App\Filament\Resources\Articles\Pages\ManageArticles;
use App\Models\Article;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * News, as it arrives from the feed.
 *
 * The linked-team badges are eager-loaded and display-capped: a story can be
 * tagged with a dozen teams and a row of twelve badges is a row nobody reads.
 */
class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'headline';

    public static function getGloballySearchableAttributes(): array
    {
        return ['headline'];
    }

    /** How many team badges a row shows before it says "+3 more". */
    private const BADGE_CAP = 3;

    public static function form(Schema $schema): Schema
    {
        // The news sync owns every column.
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The story')->columns(2)->schema([
                TextEntry::make('headline')->columnSpanFull()->weight('bold'),
                TextEntry::make('description')->columnSpanFull()->placeholder('—'),
                TextEntry::make('byline')->placeholder('—'),
                TextEntry::make('type')->badge()->color('gray')->placeholder('—'),
                TextEntry::make('published_at')->label('Published')->dateTime()->placeholder('—'),
                TextEntry::make('teams')
                    ->label('Linked teams')
                    ->columnSpanFull()
                    ->badge()
                    ->placeholder('None linked')
                    // A belongsToMany state is a COLLECTION, which Filament
                    // renders as a list of badges — one per element. That is
                    // wanted here, so it is left alone rather than collapsed.
                    ->state(fn (Article $record): array => $record->teams
                        ->map(fn ($team): string => $team->display_name)
                        ->all()),
                TextEntry::make('url')
                    ->label('Source')
                    ->placeholder('—')
                    ->url(fn (Article $record): ?string => $record->url)
                    ->openUrlInNewTab(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // The badge column reads `teams`; lazy loading is off, so naming
            // it here is not optional.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('teams'))
            ->columns([
                TextColumn::make('headline')->searchable()->wrap()->weight('medium'),
                TextColumn::make('teams')
                    ->label('Teams')
                    ->badge()
                    ->placeholder('—')
                    ->state(fn (Article $record): array => self::teamBadges($record)),
                TextColumn::make('type')->badge()->color('gray')->placeholder('—')->toggleable(),
                IconColumn::make('has_story')
                    ->label('Story')
                    ->boolean()
                    ->state(fn (Article $record): bool => filled($record->story))
                    ->tooltip('Whether the full text was fetched, or only the headline and blurb.'),
                TextColumn::make('published_at')->label('Published')->since()->color('gray')->sortable(),
            ])
            ->defaultSort('published_at', 'desc')
            ->recordActions([ViewAction::make()])
            ->toolbarActions([])
            ->emptyStateHeading('No news yet')
            ->emptyStateDescription('Stories arrive with the followed-team news sync.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageArticles::route('/'),
        ];
    }

    /**
     * The first few teams, then a count. A dozen badges in one cell is a row
     * nobody reads and a table that stops aligning.
     *
     * @return array<int, string>
     */
    private static function teamBadges(Article $record): array
    {
        $names = $record->teams->map(fn ($team): string => $team->abbreviation ?? $team->display_name);

        if ($names->count() <= self::BADGE_CAP) {
            return $names->all();
        }

        return $names->take(self::BADGE_CAP)
            ->push('+'.($names->count() - self::BADGE_CAP).' more')
            ->all();
    }
}
