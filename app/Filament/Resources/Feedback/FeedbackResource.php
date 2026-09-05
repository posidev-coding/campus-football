<?php

namespace App\Filament\Resources\Feedback;

use App\Actions\PromoteFeedback;
use App\Enums\FeedbackKind;
use App\Enums\WorkbookCategory;
use App\Enums\WorkbookSeverity;
use App\Filament\Resources\Feedback\Pages\ManageFeedback;
use App\Models\Feedback;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * The notes readers sent, as a table — where somebody reads them.
 *
 * READ-ONLY on the note itself. A person wrote it, and an edit here would
 * change what they said with nothing recording that it changed; the only
 * writes are triage — "I looked at this" and "this is work now" — and both
 * are stamps beside the note rather than edits of it.
 *
 * "This is work now" goes through {@see PromoteFeedback}, which files onto
 * the board through the human doorway and links the card back. The admin
 * writes the TITLE: open workbook titles reach the advisor through the
 * telemetry snapshot, and a reader's first sentence is not a title anybody
 * chose. Nothing about the reader crosses with it.
 *
 * Opens on the notes nobody has looked at. The handled ones are one filter
 * flip away, which is the right distance for a pile that only grows.
 */
class FeedbackResource extends Resource
{
    protected static ?string $model = Feedback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    protected static string|UnitEnum|null $navigationGroup = 'Work';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Feedback';

    protected static ?string $modelLabel = 'note';

    protected static ?string $pluralModelLabel = 'notes';

    /** How long a reader's first line may run before it stops being a title. */
    private const TITLE_PREFILL = 120;

    /**
     * The notes nobody has looked at, on the sidebar, so a pile is visible
     * without a click.
     *
     * The second badge on the rail, and the bar for a third is high: a badge
     * is only for a queue somebody empties — a stamp, an action that writes
     * it, and a table that opens on what is waiting. This has all three. Null
     * at zero, because a rail carrying "0" is a chore nobody was given.
     * PanelPolishTest holds the line.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Feedback::query()->unhandled()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function form(Schema $schema): Schema
    {
        // Nothing is editable — see the class docblock.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Lazy loading is off app-wide, and both relations render.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'workbookItem']))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->color('gray')->sortable(),
                TextColumn::make('kind')->badge()
                    ->formatStateUsing(fn (FeedbackKind $state): string => $state->label())
                    ->color(fn (FeedbackKind $state): string => $state->color()),
                TextColumn::make('body')->label('Note')->wrap()->limit(200)->searchable(),
                TextColumn::make('user')
                    ->label('Who')
                    // `name` is an accessor over two columns; a dotted column
                    // would ask MySQL for a field it does not have.
                    ->state(fn (Feedback $record): ?string => $record->user?->name)
                    // Null is a deleted account, not missing data.
                    ->placeholder('Account gone'),
                TextColumn::make('path')->label('Page')->fontFamily('mono')->size('xs')->placeholder('—'),
                TextColumn::make('release')->badge()->color('gray')->placeholder('—')->toggleable(),
                TextColumn::make('viewport')
                    ->label('Width')
                    ->formatStateUsing(fn (int $state): string => $state.'px')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('card')
                    ->label('Card')
                    ->state(fn (Feedback $record): ?string => $record->workbookItem?->reference)
                    ->fontFamily('mono')
                    ->size('xs')
                    // Null means it never became work, which is most notes.
                    ->placeholder('—'),
                TextColumn::make('handled_at')->label('Handled')->dateTime()->color('gray')->placeholder('Not yet')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('kind')->options(FeedbackKind::options()),
                // Defaulted to the notes waiting: the table opens on the
                // work, and the handled pile is one flip away.
                TernaryFilter::make('handled_at')
                    ->label('Handled')
                    ->nullable()
                    ->trueLabel('Handled')
                    ->falseLabel('Waiting')
                    ->default(false),
            ])
            ->recordActions([
                self::handled(),
                self::file(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing waiting')
            ->emptyStateDescription('Notes readers send from the app land here.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFeedback::route('/'),
        ];
    }

    /** "I looked at this." A stamp, not a status. */
    private static function handled(): Action
    {
        return Action::make('handled')
            ->label('Mark handled')
            ->icon(Heroicon::OutlinedCheck)
            ->visible(fn (Feedback $record): bool => $record->handled_at === null)
            ->action(function (Feedback $record): void {
                $record->forceFill(['handled_at' => now()])->save();

                Notification::make()->success()->title('Handled')->send();
            });
    }

    /**
     * "This is work now." Hidden for praise, which has no category to file
     * under, and for a note that already became a card — one note, one card.
     */
    private static function file(): Action
    {
        return Action::make('file')
            ->label('File as issue')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->visible(fn (Feedback $record): bool => $record->workbook_item_id === null
                && $record->kind->workbookCategory() !== null)
            ->fillForm(fn (Feedback $record): array => [
                'title' => self::titleFrom($record),
                'category' => $record->kind->workbookCategory()?->value,
                'severity' => WorkbookSeverity::Medium->value,
            ])
            ->schema([
                TextInput::make('title')->required()->maxLength(200)
                    ->helperText('Yours to write — the note\'s first line is only a start.'),
                Select::make('category')->options(WorkbookCategory::options())->required()->native(false),
                Select::make('severity')->options(WorkbookSeverity::options())->required()->native(false),
            ])
            ->action(function (Feedback $record, array $data): void {
                $item = app(PromoteFeedback::class)->handle(
                    $record,
                    (string) $data['title'],
                    WorkbookCategory::from((string) $data['category']),
                    WorkbookSeverity::from((string) $data['severity']),
                );

                Notification::make()->success()
                    ->title('Filed as '.$item->reference)
                    ->body('Run /work '.$item->reference.' to pick it up.')
                    ->send();
            });
    }

    /** The reader's first line, squished and cut — a start, never the answer. */
    private static function titleFrom(Feedback $record): string
    {
        $first = Str::of($record->body)->trim()->before("\n")->squish()->toString();

        return Str::limit($first, self::TITLE_PREFILL, '');
    }
}
