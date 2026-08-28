<?php

namespace App\Filament\Resources\Workbook;

use App\Actions\MoveWorkbookItem;
use App\Enums\WorkbookCategory;
use App\Enums\WorkbookSeverity;
use App\Enums\WorkbookStatus;
use App\Filament\Resources\Workbook\Pages\ManageWorkbook;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use UnitEnum;

/**
 * The workbook, as a table — where triage actually happens.
 *
 * Two surfaces over one model, the same shape as CoverageReport feeding both
 * `cfb:doctor` and the DataCoverage widget. This one is for searching,
 * filtering and answering several items at once; the Kanban page beside it is
 * for moving one along. A board is bad at search and worse at bulk edits, and
 * a table cannot show you where the work is piling up.
 *
 * The detail view carries the two things the advisor produced that a title
 * cannot: the EVIDENCE it was looking at, and the scaffolded Claude Code
 * prompt, copyable in one tap. That prompt is the whole reason the advisor
 * reads the repository rather than only the telemetry — "the picks screen is
 * slow" is a complaint; a prompt naming the file and the fix is a task.
 */
class WorkbookResource extends Resource
{
    protected static ?string $model = WorkbookItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Workbook';

    protected static string|UnitEnum|null $navigationGroup = 'Work';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'workbook item';

    /**
     * `workbook/items`, not the `workbook/workbooks` the directory name would
     * otherwise produce — the resource lives beside the board page, and the
     * URL should read like the two surfaces they are.
     */
    protected static ?string $slug = 'workbook/items';

    /** Open work, on the sidebar, so a full inbox is visible without a click. */
    public static function getNavigationBadge(): ?string
    {
        $open = WorkbookItem::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(200)->columnSpanFull(),
            Select::make('category')->options(WorkbookCategory::options())->required()->native(false),
            Select::make('severity')->options(WorkbookSeverity::options())->required()->native(false),
            Select::make('status')->options(WorkbookStatus::options())->required()->native(false)
                ->helperText('Dismissed is permanent as far as the advisor is concerned — it will never re-open this item, only note that the finding recurred.'),
            Textarea::make('body')->rows(6)->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title')->columnSpanFull()->weight('bold'),
            TextEntry::make('category')->badge()
                ->formatStateUsing(fn (WorkbookCategory $state): string => $state->label())
                ->color(fn (WorkbookCategory $state): string => $state->color()),
            TextEntry::make('severity')->badge()
                ->formatStateUsing(fn (WorkbookSeverity $state): string => $state->label())
                ->color(fn (WorkbookSeverity $state): string => $state->color()),
            TextEntry::make('first_seen_at')->label('First seen')->since()
                ->helperText('How long this has been true — never reset by a re-propose.'),
            TextEntry::make('last_seen_at')->label('Last seen')->since(),
            TextEntry::make('body')->label('What was found')->columnSpanFull()->placeholder('—'),
            TextEntry::make('evidence')
                ->label('Evidence')
                ->columnSpanFull()
                ->placeholder('—')
                /*
                 * `state()`, NOT `formatStateUsing()`, and the difference is a
                 * TypeError rather than a style choice.
                 *
                 * `evidence` carries an `array` cast, and Filament renders an
                 * array state as a LIST — it calls the formatter once per
                 * ELEMENT, handing it the element. So a `?array` hint blows up
                 * on the first `['hits' => 214]`, at the moment somebody opens
                 * the item, with the row rendering perfectly in the table
                 * behind it.
                 *
                 * Overriding the state collapses it to one string before
                 * Filament ever sees an array. Pretty-printed rather than a
                 * KeyValueEntry, because evidence is arbitrary nested JSON
                 * from a telemetry snapshot and a flat key/value list would
                 * drop everything below the surface.
                 */
                ->state(fn (WorkbookItem $record): ?string => $record->evidence === null
                    ? null
                    : json_encode($record->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                ->fontFamily('mono')
                ->size('xs'),
            TextEntry::make('prompt')
                ->label('Claude Code prompt')
                ->columnSpanFull()
                ->placeholder('—')
                ->fontFamily('mono')
                ->size('xs')
                // The point of the whole detail view. Copy, paste into a
                // session, done. Needs SSL, which Herd and production both have.
                ->copyable()
                ->copyMessage('Prompt copied')
                ->copyMessageDuration(1500),
            TextEntry::make('key')->label('Idempotency key')->fontFamily('mono')->size('xs')
                ->helperText('What the advisor re-proposes under. Changing it files a duplicate.'),
            TextEntry::make('source')->badge()->color('gray'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->wrap()->weight('medium'),
                TextColumn::make('category')->badge()
                    ->formatStateUsing(fn (WorkbookCategory $state): string => $state->label())
                    ->color(fn (WorkbookCategory $state): string => $state->color())
                    ->searchable(),
                TextColumn::make('severity')->badge()
                    ->formatStateUsing(fn (WorkbookSeverity $state): string => $state->label())
                    ->color(fn (WorkbookSeverity $state): string => $state->color()),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (WorkbookStatus $state): string => $state->label())
                    ->color(fn (WorkbookStatus $state): string => $state->color()),
                TextColumn::make('first_seen_at')->label('First seen')->since()->color('gray')->sortable(),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->color('gray')->sortable(),
            ])
            /*
             * Worst first. NOT `->defaultSort('severity')`: the column holds
             * the enum's string value, so alphabetical order is
             * critical-high-low-medium, which puts Low above Medium and reads
             * as a bug in the board rather than a sort.
             */
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw("field(severity, 'critical', 'high', 'medium', 'low')")
                ->orderByDesc('last_seen_at'))
            ->filters([
                SelectFilter::make('status')->multiple()->options(WorkbookStatus::options()),
                SelectFilter::make('category')->multiple()->options(WorkbookCategory::options()),
                SelectFilter::make('severity')->multiple()->options(WorkbookSeverity::options()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::edit(),
                self::move(),
            ])
            ->toolbarActions([
                self::moveTo(WorkbookStatus::Planned),
                self::moveTo(WorkbookStatus::Done),
                self::moveTo(WorkbookStatus::Dismissed),
            ])
            ->emptyStateHeading('Nothing proposed yet')
            ->emptyStateDescription('The advisor files here after it reads a telemetry snapshot.')
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWorkbook::route('/'),
        ];
    }

    /**
     * The edit form, saved through the doorway rather than around it.
     *
     * Filament's default save is `$record->update($data)`, which writes
     * `status` directly and leaves no event — one of the four holes the trail
     * exists to close. `using()` pulls the status out and hands it to
     * `MoveWorkbookItem`; everything else saves normally.
     */
    private static function edit(): EditAction
    {
        return EditAction::make()->using(function (WorkbookItem $record, array $data): WorkbookItem {
            $status = WorkbookStatus::from(Arr::pull($data, 'status'));
            $note = Arr::pull($data, 'move_note');

            $record->update($data);

            return app(MoveWorkbookItem::class)
                ->handle($record->id, $status, actor: WorkbookEvent::ACTOR_HUMAN, note: $note) ?? $record;
        });
    }

    /**
     * Move one item, WITH a reason.
     *
     * A form save has nowhere to put a note, and the note is what makes a trail
     * worth opening — "moved to planned" is a fact anyone could have guessed,
     * "moved to planned, waiting on the ESPN feed fix" is a record.
     */
    private static function move(): Action
    {
        return Action::make('move')
            ->label('Move')
            ->icon(Heroicon::OutlinedArrowRight)
            ->color('gray')
            ->fillForm(fn (WorkbookItem $record): array => ['status' => $record->status->value])
            ->schema([
                Select::make('status')->options(WorkbookStatus::options())->required()->native(false),
                Textarea::make('note')->label('Why')->rows(3)
                    ->helperText('Goes on the activity trail, for whoever reads this in a month.'),
            ])
            ->action(fn (WorkbookItem $record, array $data) => app(MoveWorkbookItem::class)->handle(
                $record->id,
                WorkbookStatus::from($data['status']),
                actor: WorkbookEvent::ACTOR_HUMAN,
                note: $data['note'] ?? null,
            ));
    }

    /**
     * Answering several items at once — the thing a Kanban cannot do, and the
     * reason this surface exists beside the board.
     */
    private static function moveTo(WorkbookStatus $status): BulkAction
    {
        return BulkAction::make("move_{$status->value}")
            ->label("Move to {$status->label()}")
            ->icon($status === WorkbookStatus::Dismissed ? Heroicon::OutlinedXCircle : Heroicon::OutlinedArrowRight)
            ->color($status->color())
            ->requiresConfirmation()
            ->modalDescription($status === WorkbookStatus::Dismissed
                ? 'The advisor will never re-open a dismissed item. It will only record that the finding recurred.'
                : null)
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($status): void {
                // Through the action, never `$item->update([...])` — a direct
                // write here was one of the four holes in the trail.
                //
                // `position: null` means APPEND, which is exactly the old
                // behavior: each item lands at the end in the order it was
                // selected, so a bulk move does not interleave with what is
                // already in the column. It is NOT `0`, which is the top.
                $records->each(fn (WorkbookItem $item) => app(MoveWorkbookItem::class)
                    ->handle($item->id, $status, position: null, actor: WorkbookEvent::ACTOR_HUMAN));
            });
    }
}
