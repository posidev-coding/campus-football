<?php

namespace App\Filament\Resources\Workbook;

use App\Actions\MoveWorkbookItem;
use App\Actions\ReadyWorkbookItem;
use App\Actions\StartWorkbookItem;
use App\Enums\WorkbookCategory;
use App\Enums\WorkbookEffort;
use App\Enums\WorkbookSeverity;
use App\Enums\WorkbookStatus;
use App\Filament\Resources\Workbook\Pages\ManageWorkbook;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use App\Support\IssueBoard;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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
            // No default. Null means NOT SIZED, which is a real answer — a cast
            // to Medium fills the ready queue with work nobody estimated.
            Select::make('effort')->label('Effort')->options(WorkbookEffort::options())->native(false)
                ->placeholder('Not sized'),
            // Produces an array, a direct fit for the JSON column. The
            // normalizing is the model's mutator's job, not the form's, so
            // every path lands on one vocabulary.
            TagsInput::make('labels')->columnSpanFull()->placeholder('performance, frontend, …'),
            Textarea::make('body')->rows(6)->columnSpanFull(),
            /*
             * Read by `using()` and never written to the row.
             *
             * NOT `->dehydrated(false)`: that would drop it from the state
             * `using()` is handed, which is the only thing that reads it.
             * `Arr::pull()` in `using()` is what keeps it off `update()`.
             */
            Textarea::make('move_note')->label('Why (for the trail)')->rows(2)->columnSpanFull()
                ->helperText('Recorded only if the status changes.')
                // Nothing to explain about a card that did not exist a moment
                // ago — and a hidden field is not dehydrated, which keeps
                // `move_note` off the CreateAction's mass assignment.
                ->hiddenOn('create'),
        ]);

        // Branch, PR and the claim never appear on a form. They are the action
        // layer's, and a mass-assignable claim is a claim anyone can forge.
    }

    public static function infolist(Schema $schema): Schema
    {
        /*
         * Sections, not a flat `->columns(2)`. With the work, the links and the
         * trail added, a flat two-column grid is a wall — the reader cannot
         * tell what the advisor found from what a human decided about it, which
         * is the one distinction this whole surface is built on.
         *
         * Deliberately NOT a relation manager: those render only on
         * ViewRecord/EditRecord pages, and this resource is a ManageRecords with
         * modals. And deliberately not a custom blade timeline, tempting as it
         * is now the panel compiles its own Tailwind — a blade outside
         * `resources/views/filament/**` compiles to NO CSS at all, silently.
         */
        return $schema->components([
            Section::make('The finding')
                ->description('What the advisor recomputes every week, and therefore owns.')
                ->columns(2)
                ->schema([
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
                ]),

            Section::make('The work')
                ->description('What a human decided. The advisor cannot reach any of it.')
                ->columns(2)
                ->schema([
                    TextEntry::make('reference')
                        ->label('Reference')
                        ->fontFamily('mono')
                        // The cell READS `CFB-12` and COPIES `/work CFB-12`,
                        // which is the entire hand-off: one tap here, one paste
                        // into a session.
                        ->copyable()
                        ->copyableState(fn (WorkbookItem $record): string => "/work {$record->reference}")
                        ->copyMessage('Hand-off copied')
                        ->copyMessageDuration(1500),
                    TextEntry::make('effort')->badge()->placeholder('Not sized')
                        ->formatStateUsing(fn (WorkbookEffort $state): string => $state->label())
                        ->color(fn (WorkbookEffort $state): string => $state->color()),
                    // `labels` has an array cast, so Filament renders it as a
                    // list and gives one badge per element for free. Do NOT add
                    // a `?array` formatter here.
                    TextEntry::make('labels')->badge()->placeholder('—')->columnSpanFull(),
                    TextEntry::make('branch')->fontFamily('mono')->size('xs')->placeholder('Not started')
                        ->copyable()
                        ->helperText('The durable copy of the reference. Never renamed.'),
                    /*
                     * Reads as a summary, COPIES the whole hand-off — the
                     * `reference` cell's copyableState trick, scaled up. The
                     * paste target is a Claude Code session on a machine that
                     * may not reach this board at all, so the copy carries the
                     * full brief rather than a reference the session would
                     * have to look up.
                     */
                    TextEntry::make('handoff')
                        ->label('Session hand-off')
                        ->columnSpanFull()
                        ->fontFamily('mono')
                        ->size('xs')
                        ->state(fn (WorkbookItem $record): ?string => $record->branch === null
                            ? null
                            : "/work {$record->reference} + the brief + git switch -c {$record->branch}")
                        ->placeholder('Start the issue first — the hand-off carries the branch it stores.')
                        ->copyable()
                        ->copyableState(fn (WorkbookItem $record): string => $record->branch === null
                            ? ''
                            : self::handoff($record))
                        ->copyMessage('Hand-off copied')
                        ->copyMessageDuration(1500)
                        ->helperText('One paste into a Claude Code session: the /work line, the full brief as cfb:issue show --json prints it, and the branch line.'),
                    TextEntry::make('pr_url')->label('Pull request')->placeholder('—')
                        ->url(fn (WorkbookItem $record): ?string => $record->pr_url)
                        ->openUrlInNewTab(),
                    TextEntry::make('ready_at')->label('Ready')->since()->placeholder('Not ready'),
                    TextEntry::make('started_at')->label('Started')->since()->placeholder('—'),
                    TextEntry::make('claimed_by')->label('Held by')->badge()->color('warning')
                        ->visible(fn (WorkbookItem $record): bool => $record->isHeld())
                        ->helperText(fn (WorkbookItem $record): string => 'Lease ends '
                            .($record->claim_expires_at?->diffForHumans() ?? 'never')),
                ]),

            Section::make('Links')
                ->visible(fn (WorkbookItem $record): bool => $record->renderedLinks !== [])
                ->schema([
                    RepeatableEntry::make('renderedLinks')
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('Relation'),
                            TableColumn::make('Issue'),
                            TableColumn::make('Title'),
                            TableColumn::make('Status'),
                        ])
                        ->schema([
                            TextEntry::make('label'),
                            TextEntry::make('reference')->fontFamily('mono')->size('xs'),
                            TextEntry::make('title'),
                            TextEntry::make('status')->badge(),
                        ]),
                ]),

            Section::make('Activity')
                ->visible(fn (WorkbookItem $record): bool => $record->events()->exists())
                ->schema([
                    RepeatableEntry::make('trail')
                        ->hiddenLabel()
                        // Newest first, which is how anybody actually reads a
                        // trail; the relation itself is ordered oldest-first
                        // because that is how a session replays one.
                        ->state(fn (WorkbookItem $record): array => $record->events()
                            ->latest('created_at')->latest('id')->limit(20)->get()
                            ->map(fn (WorkbookEvent $event): array => [
                                'kind' => $event->kind,
                                'actor' => $event->actor,
                                'note' => $event->note ?? '—',
                                'when' => $event->created_at?->diffForHumans() ?? '',
                            ])
                            ->all())
                        ->table([
                            TableColumn::make('What'),
                            TableColumn::make('Who'),
                            TableColumn::make('Why'),
                            TableColumn::make('When'),
                        ])
                        ->schema([
                            TextEntry::make('kind')->badge()->color('gray'),
                            TextEntry::make('actor')->fontFamily('mono')->size('xs'),
                            TextEntry::make('note'),
                            TextEntry::make('when')->color('gray'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                /*
                 * The handle, and the whole hand-off in one cell: it READS
                 * `CFB-12` and COPIES `/work CFB-12`, which is what
                 * `copyableState()` is for.
                 *
                 * `reference` is DERIVED — there is no such column — so sorting
                 * and searching both need explicit closures or MySQL answers
                 * 1054 on a column that does not exist.
                 */
                TextColumn::make('reference')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->copyable()
                    ->copyableState(fn (WorkbookItem $record): string => "/work {$record->reference}")
                    ->copyMessage('Hand-off copied')
                    ->copyMessageDuration(1500)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('id', $direction))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->when(
                        preg_match('/(\d+)\s*$/', $search, $matches) === 1,
                        fn (Builder $query): Builder => $query->orWhere('id', (int) $matches[1]),
                    )),
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
                TextColumn::make('effort')->badge()->placeholder('—')
                    ->formatStateUsing(fn (WorkbookEffort $state): string => $state->label())
                    ->color(fn (WorkbookEffort $state): string => $state->color())
                    ->toggleable(),
                // An array cast, so Filament renders a LIST and gives one badge
                // per label for free. A `?array` formatter here would be a
                // TypeError on the first row that has any.
                TextColumn::make('labels')->badge()->placeholder('—')->toggleable(),
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
                SelectFilter::make('effort')->multiple()->options(WorkbookEffort::options()),
                /*
                 * Distinct in PHP over the JSON column, which is correct at
                 * this size and is exactly why labels are a column rather than
                 * a pivot: the only two questions anyone asks are "show them on
                 * a card" and "filter to one".
                 */
                SelectFilter::make('label')
                    ->label('Label')
                    ->options(fn (): array => WorkbookItem::query()
                        ->whereNotNull('labels')
                        ->pluck('labels')
                        ->flatten()
                        ->unique()
                        ->sort()
                        ->mapWithKeys(fn (string $label): array => [$label => $label])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $label): Builder => $query->whereJsonContains('labels', $label),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                self::start(),
                self::edit(),
                self::move(),
                self::ready(),
            ])
            /*
             * Deliberately NO bulk "move to In review". In review means a pull
             * request is open and waiting on a human; a bulk move puts cards
             * there without one, and a column that lies is worse than a column
             * nobody uses.
             */
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
     * `cfb:issue start`, as a button — so working a card does not begin in a
     * cloud console.
     *
     * The same transition through the same action: take the claim, store the
     * branch, move the card to In progress, one `started` row on the trail.
     * The panel acts as `human`, exactly as it does everywhere else — this is
     * a person deciding to hand the card to a session they are driving.
     *
     * Refusal is a notification, not an exception: somebody else holding the
     * claim is a normal state of the board, and `StartWorkbookItem` never
     * steals.
     */
    private static function start(): Action
    {
        return Action::make('start')
            ->label('Start')
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->visible(fn (WorkbookItem $record): bool => in_array(
                $record->status, [WorkbookStatus::Inbox, WorkbookStatus::Planned], true,
            ))
            ->requiresConfirmation()
            ->modalHeading(fn (WorkbookItem $record): string => "Start {$record->reference}")
            ->modalDescription('Takes the claim, stores the branch, and moves the card to In progress — what `cfb:issue start` does at a terminal. The view modal then carries a copyable session hand-off.')
            ->action(function (WorkbookItem $record): void {
                $started = app(StartWorkbookItem::class)->handle($record, WorkbookEvent::ACTOR_HUMAN);

                if ($started === null) {
                    Notification::make()
                        ->danger()
                        ->title("{$record->reference} is already held")
                        ->body("By `{$record->fresh()?->claimed_by}`. The lease frees itself when it lapses; moving the card releases it sooner.")
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title("{$started->reference} started")
                    ->body("Branch stored: `{$started->branch}`. Open the item to copy the session hand-off.")
                    ->send();
            });
    }

    /**
     * Everything a session needs, in one paste.
     *
     * Exists because a LOCAL session cannot reach THIS board when this is the
     * production panel — the ops token deliberately never lives on a laptop —
     * so `/work CFB-12` alone leaves the session asking for the brief. The
     * block carries the `/work` line, the brief exactly as
     * `cfb:issue show --json` prints it, and the stored branch line.
     *
     * Public so the test reads the same composer the copy button uses —
     * `copyableState` renders into a JS attribute no modal assertion can
     * reach.
     */
    public static function handoff(WorkbookItem $record): string
    {
        $brief = json_encode(
            app(IssueBoard::class)->one($record),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        return implode("\n", [
            "/work {$record->reference}",
            '',
            'The brief, as `cfb:issue show --json` prints it — this board may not be reachable from the working checkout:',
            '',
            $brief,
            '',
            'Already started on the board. Cut the branch exactly as stored:',
            '',
            "git switch -c {$record->branch}",
        ]);
    }

    /**
     * Ready is not the same fact as planned.
     *
     * Planned means we intend to do this; ready means the brief is complete
     * enough that an agent can start without asking anybody a question. The
     * confirmation says so, because the consequence is a cloud routine claiming
     * this at 3am and working from whatever is written on it.
     */
    private static function ready(): Action
    {
        return Action::make('ready')
            ->label('Mark ready')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (WorkbookItem $record): bool => $record->ready_at === null)
            ->requiresConfirmation()
            ->modalDescription('A cloud routine may claim this and start work from whatever is written on it. Ready means the brief is finished.')
            ->action(fn (WorkbookItem $record) => app(ReadyWorkbookItem::class)
                ->handle($record, WorkbookEvent::ACTOR_HUMAN));
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
