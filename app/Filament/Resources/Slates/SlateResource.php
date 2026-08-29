<?php

namespace App\Filament\Resources\Slates;

use App\Enums\ContestMode;
use App\Filament\Resources\Slates\Pages\ListSlates;
use App\Filament\Resources\Slates\Pages\ViewSlate;
use App\Filament\Resources\Slates\RelationManagers\EntriesRelationManager;
use App\Filament\Resources\Slates\RelationManagers\GamesRelationManager;
use App\Models\Slate;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * A slate: one contest's games for one Saturday.
 *
 * READ-ONLY, and that is the whole design. `PublishSlate` owns publication
 * (with its validation), `AddSlateGame`/`RemoveSlateGame` own the lineup,
 * `SetSlateGameLine` owns the spreads and `SettleSlate` owns the results and
 * the payouts. Every one of those does something a form cannot: freeze a line,
 * pay a keyed reward, refuse an invalid slate. An editable status column here
 * would let somebody type `settled` and pay nobody.
 *
 * The Saturday, not `week_id`, is the slate's identity — one ESPN week can
 * hold two Saturdays.
 */
class SlateResource extends Resource
{
    protected static ?string $model = Slate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = "Pick'em";

    protected static ?int $navigationSort = 2;

    /**
     * Lifecycle order, which is what FIELD() is for below and what every
     * status badge in the panel reads.
     */
    public const STATUS_ORDER = [Slate::DRAFT, Slate::PUBLISHED, Slate::PRELIM, Slate::SETTLED];

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            Slate::DRAFT => 'Draft',
            Slate::PUBLISHED => 'Published',
            Slate::PRELIM => 'Preliminary',
            Slate::SETTLED => 'Settled',
            default => $status,
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            Slate::DRAFT => 'gray',
            Slate::PUBLISHED => 'info',
            // Every game final but the week not yet official — the window
            // where a late ESPN correction can still move a tiebreaker.
            Slate::PRELIM => 'warning',
            Slate::SETTLED => 'success',
            default => 'gray',
        };
    }

    public static function form(Schema $schema): Schema
    {
        // Read-only resource: there is no edit page to render this.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Every column below reads through contest → group. Lazy loading
            // is off, so an unnamed relation here is a 500, and no feature
            // test can catch a missing eager load.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('contest.group'))
            ->columns([
                TextColumn::make('saturday')->date('M j, Y')->sortable(),
                TextColumn::make('contest.group.name')->label('Group')->placeholder('—')->wrap(),
                TextColumn::make('contest.mode')->label('Mode')->badge()
                    ->formatStateUsing(fn (ContestMode $state): string => $state->label()),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state))
                    /*
                     * `status` is a plain string column, so alphabetical order
                     * is draft-prelim-published-settled — prelim above
                     * published, which reads as a bug in the table rather than
                     * a sort. FIELD() puts it in lifecycle order.
                     */
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw("field(status, 'draft', 'published', 'prelim', 'settled') "
                            .($direction === 'desc' ? 'desc' : 'asc'))),
                TextColumn::make('games_count')->label('Games')->counts('games'),
                TextColumn::make('entries_count')->label('Entries')->counts('entries'),
                IconColumn::make('exhibition')->boolean()
                    ->tooltip('A practice slate: graded and paid, never counted.'),
                TextColumn::make('bear_theme')->label('Bear')->placeholder('—')->toggleable(),
                TextColumn::make('published_at')->label('Published')->since()->color('gray')->placeholder('Draft'),
            ])
            ->defaultSort('saturday', 'desc')
            ->filters([
                SelectFilter::make('status')->multiple()->options(
                    collect(self::STATUS_ORDER)->mapWithKeys(
                        fn (string $status): array => [$status => self::statusLabel($status)],
                    )->all(),
                ),
                TernaryFilter::make('exhibition')->label('Exhibition'),
                SelectFilter::make('mode')
                    ->label('Mode')
                    ->options(collect(ContestMode::cases())->mapWithKeys(
                        fn (ContestMode $mode): array => [$mode->value => $mode->label()],
                    ))
                    // The column lives on `contests`, so the filter has to
                    // reach through the relation rather than name a column.
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $mode): Builder => $query->whereHas(
                            'contest',
                            fn (Builder $query): Builder => $query->where('mode', $mode),
                        ),
                    )),
                Filter::make('saturday')
                    ->schema([
                        DatePicker::make('from')->label('Saturdays from'),
                        DatePicker::make('until')->label('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('saturday', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('saturday', '<=', $date))),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([])
            ->emptyStateHeading('No slates published yet');
    }

    public static function getRelations(): array
    {
        return [
            GamesRelationManager::class,
            EntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSlates::route('/'),
            'view' => ViewSlate::route('/{record}'),
        ];

        // No create and no edit — see the class docblock. Every write to a
        // slate is an Action that does something a form cannot.
    }
}
