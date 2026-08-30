<?php

namespace App\Filament\Pages;

use App\Actions\MoveWorkbookItem;
use App\Enums\WorkbookCategory;
use App\Enums\WorkbookEffort;
use App\Enums\WorkbookSeverity;
use App\Enums\WorkbookStatus;
use App\Filament\Resources\Workbook\WorkbookResource;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Js;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The board — the second surface over `workbook_items`, and the one for
 * moving work along rather than finding it.
 *
 * A Kanban is bad at search and worse at bulk edits, which is why the Resource
 * beside it exists; it is good at the one thing a table cannot do, which is
 * showing where the work is piling up. Same "one object, two surfaces" shape
 * as CoverageReport feeding `cfb:doctor` and the DataCoverage widget.
 *
 * IT LIVES IN THE PANEL, and that is what makes it cheap. A board needs
 * horizontal scroll, and `ChromeConsistencyTest` bans that everywhere in the
 * product — but it excludes `filament/` on the stated reasoning that holding
 * an admin table to the phone-first rules is the right rule on the wrong
 * product. So the board needs no allowlist edit and no weakened sweep.
 *
 * Hand-written Tailwind here is only possible because the panel now has its
 * own compiled theme; before that every class in this file would have been
 * silently absent. Flux is still unavailable — `<flux:kanban>` needs Flux's
 * own bundles — but little is lost, since a column is `w-80 rounded-lg` and a
 * wrapper is `flex gap-4`.
 */
class Workbook extends Page
{
    protected string $view = 'filament.pages.workbook';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'Work';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Board';

    protected static ?string $title = 'Workbook board';

    /**
     * The board's read controls, in the URL so a narrowed board can be shared.
     *
     * '' means "everything", never null — these bind to native selects, and an
     * empty option value round-trips as the empty string anyway.
     */
    #[Url]
    public string $severity = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $effort = '';

    #[Url]
    public string $label = '';

    /** '' | severity | category | effort — subgroups within each column. */
    #[Url]
    public string $group = '';

    public function mount(): void
    {
        $this->normalizeControls();
    }

    /**
     * Needed in BOTH places: `#[Url]` hydrates from the querystring without
     * firing the update hook, so a bookmarked `?severity=nonsense` would
     * otherwise filter every column to nothing and read as an empty board.
     */
    public function updated(): void
    {
        $this->normalizeControls();
    }

    private function normalizeControls(): void
    {
        $this->severity = array_key_exists($this->severity, WorkbookSeverity::options()) ? $this->severity : '';
        $this->category = array_key_exists($this->category, WorkbookCategory::options()) ? $this->category : '';
        $this->effort = array_key_exists($this->effort, WorkbookEffort::options()) ? $this->effort : '';
        $this->group = in_array($this->group, ['severity', 'category', 'effort'], true) ? $this->group : '';
    }

    public function clearControls(): void
    {
        $this->severity = '';
        $this->category = '';
        $this->effort = '';
        $this->label = '';
        $this->group = '';
    }

    /**
     * Whether the drag is live.
     *
     * Only on the board's natural state. A FILTERED column hides cards, so
     * Sortable's index counts visible ones while positions are measured
     * against the whole column — the drop lands somewhere the eye did not put
     * it. A GROUPED column is not in position order at all. Reordering what
     * you cannot fully see is the same class of bug either way, so the handle
     * disappears rather than lying.
     */
    #[Computed]
    public function sortable(): bool
    {
        return $this->severity === ''
            && $this->category === ''
            && $this->effort === ''
            && $this->label === ''
            && $this->group === '';
    }

    /**
     * Every column with its items — ONE query for the whole board, grouped in
     * memory, never a query per column.
     *
     * Each column also carries `groups`: one unlabeled bucket on the natural
     * board, or the group-by control's subgroups in the vocabulary's own order
     * — so the blade renders ONE card markup wherever a card appears.
     *
     * @return list<array{status: WorkbookStatus, items: Collection<int, WorkbookItem>, groups: list<array{label: ?string, items: Collection<int, WorkbookItem>}>}>
     */
    #[Computed]
    public function columns(): array
    {
        $byStatus = WorkbookItem::query()
            /*
             * `linksIn` is where "blocked by" lives, because only `blocks` is
             * ever stored — and the badge needs the BLOCKER's status, which is
             * why the relation eager-loads its own far end.
             *
             * Eager, not lazy, and no feature test can prove it: the
             * per-instance `preventsLazyLoading` flag is false under test, so
             * an unloaded relation resolves silently here and throws only in a
             * browser. The board's query-count ceiling is the guard.
             */
            ->with('linksIn')
            ->whereIn('status', array_map(fn (WorkbookStatus $s): string => $s->value, WorkbookStatus::columns()))
            ->when($this->severity !== '', fn (Builder $query): Builder => $query->where('severity', $this->severity))
            ->when($this->category !== '', fn (Builder $query): Builder => $query->where('category', $this->category))
            ->when($this->effort !== '', fn (Builder $query): Builder => $query->where('effort', $this->effort))
            ->when($this->label !== '', fn (Builder $query): Builder => $query->whereJsonContains('labels', $this->label))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (WorkbookItem $item): string => $item->status->value);

        return array_map(fn (WorkbookStatus $status): array => [
            'status' => $status,
            'items' => $items = $byStatus->get($status->value, collect()),
            'groups' => $this->subgroups($items),
        ], WorkbookStatus::columns());
    }

    /**
     * A column's cards, bucketed by the group-by control.
     *
     * Subgroups come in the VOCABULARY'S order — worst severity first, the
     * category enum's order, s/m/l — never alphabetical, which is the same
     * FIELD() reasoning as the table's sort. Empty buckets are skipped: a
     * heading over nothing is noise. Unsized effort is a real bucket and comes
     * last, because "nobody estimated this" is an answer, not a blank.
     *
     * @param  Collection<int, WorkbookItem>  $items
     * @return list<array{label: ?string, items: Collection<int, WorkbookItem>}>
     */
    private function subgroups(Collection $items): array
    {
        if ($this->group === '') {
            return [['label' => null, 'items' => $items]];
        }

        $buckets = $items->groupBy(fn (WorkbookItem $item): string => match ($this->group) {
            'severity' => $item->severity->value,
            'category' => $item->category->value,
            default => $item->effort?->value ?? '',
        });

        $order = match ($this->group) {
            'severity' => array_column(WorkbookSeverity::cases(), 'value'),
            'category' => array_column(WorkbookCategory::cases(), 'value'),
            default => [...array_column(WorkbookEffort::cases(), 'value'), ''],
        };

        $groups = [];

        foreach ($order as $key) {
            $bucket = $buckets->get($key);

            if ($bucket === null || $bucket->isEmpty()) {
                continue;
            }

            $groups[] = [
                'label' => match ($this->group) {
                    'severity' => WorkbookSeverity::from($key)->label(),
                    'category' => WorkbookCategory::from($key)->label(),
                    default => $key === '' ? 'Not sized' : WorkbookEffort::from($key)->label(),
                },
                'items' => $bucket,
            ];
        }

        return $groups;
    }

    /**
     * The label vocabulary actually in use — same source as the table's
     * filter, so the two surfaces can never offer different lists.
     *
     * @return list<string>
     */
    #[Computed]
    public function labelOptions(): array
    {
        return WorkbookItem::query()
            ->whereNotNull('labels')
            ->pluck('labels')
            ->flatten()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * `wire:sort`'s handler. It reports ONE item and its new index, never the
     * whole list — and the third argument is this column's
     * `wire:sort:group-id`, which is how a cross-column drop says where it
     * landed. The id arrives as a STRING.
     */
    public function move(string $item, int $position, string $status): void
    {
        /*
         * The server-side half of "the drag is off while narrowed". The blade
         * withholds the sort attributes, but a stale DOM from before a filter
         * landed can still fire — and its index counts the cards it could
         * see, not the column.
         */
        if (! $this->sortable) {
            return;
        }

        $column = WorkbookStatus::tryFrom($status);

        if ($column === null) {
            return;
        }

        app(MoveWorkbookItem::class)->handle((int) $item, $column, $position, actor: WorkbookEvent::ACTOR_HUMAN);

        unset($this->columns);
    }

    /**
     * The worst ready issue nobody is holding — what `cfb:issue start` would
     * pick up next, without taking the claim.
     */
    #[Computed]
    public function nextReady(): ?WorkbookItem
    {
        return WorkbookItem::query()
            ->where('status', WorkbookStatus::Planned->value)
            ->whereNotNull('ready_at')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('claimed_at')
                ->orWhere('claim_expires_at', '<', now()))
            ->orderByRaw("field(severity, 'critical', 'high', 'medium', 'low')")
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    /**
     * The card's own modal — the SAME detail view the table's View action
     * opens, because two renderings of one item that disagree is how a board
     * stops being trusted.
     *
     * Mounted by a click anywhere on the card rather than by a button: a card
     * is a summary, and the thing a reader wants after reading a summary is
     * the rest of it. The buttons already inside the card all stop
     * propagation, and so does the drag handle, so the only click that reaches
     * here is one aimed at the card itself.
     *
     * `disabled()` rather than a null check inside: the record resolves before
     * the modal mounts, and ViewAction fills the schema from a non-nullable
     * `Model $record`. A card whose item was deleted since the board rendered
     * would therefore TypeError at the moment somebody clicked it; disabled
     * unmounts cleanly instead.
     */
    public function viewAction(): Action
    {
        return ViewAction::make('view')
            ->disabled(fn (array $arguments): bool => ! WorkbookItem::query()
                ->whereKey($arguments['item'] ?? 0)
                ->exists())
            ->record(fn (array $arguments): ?WorkbookItem => WorkbookItem::query()->find($arguments['item'] ?? 0))
            ->modalHeading(function (array $arguments): string {
                $record = WorkbookItem::query()->find($arguments['item'] ?? 0);

                return $record === null ? 'This card' : "{$record->reference} — {$record->title}";
            })
            ->modalWidth(Width::ThreeExtraLarge)
            ->schema(WorkbookResource::detailSchema());
    }

    /**
     * `cfb:issue start`, from a card — the same shared transition the table's
     * Start action runs, mounted with the card's id as an argument.
     *
     * Confirmed first: on a board the button sits a few pixels from the drag
     * handle, and a misclicked start writes a claim, a branch and a trail row.
     */
    public function startAction(): Action
    {
        return Action::make('start')
            ->requiresConfirmation()
            ->modalHeading(function (array $arguments): string {
                $reference = WorkbookItem::query()->find($arguments['item'] ?? 0)?->reference;

                return 'Start '.($reference ?? 'this card');
            })
            ->modalDescription('Takes the claim, stores the branch, and moves the card to In progress — what `cfb:issue start` does at a terminal. The card\'s clipboard button then copies the full session hand-off.')
            ->action(function (array $arguments): void {
                $record = WorkbookItem::query()->find($arguments['item'] ?? 0);

                if ($record === null) {
                    return;
                }

                WorkbookResource::startAsHuman($record);

                unset($this->columns);
            });
    }

    /**
     * `cfb:issue review --pr=`, from a card — the same transition, the same
     * field and the same two voices as the table's Review action, mounted with
     * the card's id the way `startAction()` is.
     *
     * A drag to In review is NOT this: it sets the column and leaves `pr_url`
     * null and the claim held, and the merge webhook then closes the card
     * carrying no record of what closed it. The board needed a doorway that
     * records the pull request, and this is it.
     *
     * The form is also the confirmation. On a board this button sits a few
     * pixels from the drag handle, and a modal with a required URL in it
     * cannot be submitted by a misclick.
     */
    public function reviewAction(): Action
    {
        return Action::make('review')
            ->modalHeading(function (array $arguments): string {
                $reference = WorkbookItem::query()->find($arguments['item'] ?? 0)?->reference;

                return 'Hand '.($reference ?? 'this card').' to review';
            })
            ->modalDescription('Records the pull request, moves the card to In review and releases the claim — what `cfb:issue review --pr=` does at a terminal. Merging is what earns Done.')
            ->fillForm(fn (array $arguments): array => [
                'pr_url' => WorkbookItem::query()->find($arguments['item'] ?? 0)?->pr_url,
            ])
            ->schema(WorkbookResource::reviewSchema())
            ->modalSubmitActionLabel('Hand it on')
            ->action(function (array $arguments, array $data): void {
                $record = WorkbookItem::query()->find($arguments['item'] ?? 0);

                if ($record === null) {
                    return;
                }

                WorkbookResource::reviewAsHuman($record, (string) $data['pr_url'], $data['note'] ?? null);

                unset($this->columns);
            });
    }

    /**
     * The session hand-off, from a card — a modal rather than an inline copy,
     * because composing the block reads the trail and the links, and that
     * must cost queries once on a click, never once per card on render.
     */
    public function handoffAction(): Action
    {
        return Action::make('handoff')
            ->modalHeading(function (array $arguments): string {
                $reference = WorkbookItem::query()->find($arguments['item'] ?? 0)?->reference;

                return 'Session hand-off'.($reference === null ? '' : " — {$reference}");
            })
            ->modalDescription('One paste into a Claude Code session: the /work line, the full brief as cfb:issue show --json prints it, and the branch line.')
            ->modalContent(function (array $arguments) {
                $record = WorkbookItem::query()->find($arguments['item'] ?? 0);

                return view('filament.pages.partials.workbook-handoff', [
                    'handoff' => $record === null || $record->branch === null
                        ? 'Start the issue first — the hand-off carries the branch it stores.'
                        : WorkbookResource::handoff($record),
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * One tap from the board to a session. Client-side only — there is
             * no `->action()`, because the whole job is
             * `navigator.clipboard.writeText` and a round trip to the server to
             * copy a string is a round trip for nothing.
             *
             * Untestable at the clipboard: `navigator.clipboard` needs a secure
             * context and is absent from the automated tab. The test asserts
             * the rendered attribute, per "test through the layer a test can
             * hold".
             */
            Action::make('next')
                ->label('Copy the next ready issue')
                ->icon(Heroicon::OutlinedClipboardDocument)
                ->color('gray')
                ->visible(fn (): bool => $this->nextReady() !== null)
                ->extraAttributes(fn (): array => [
                    'x-data' => '',
                    'x-on:click.prevent' => 'navigator.clipboard?.writeText('
                        .Js::from('/work '.$this->nextReady()?->reference).')',
                ]),
            Action::make('table')
                ->label('Open the table')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->url(WorkbookResource::getUrl()),
        ];
    }
}
