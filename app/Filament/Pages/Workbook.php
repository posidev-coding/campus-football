<?php

namespace App\Filament\Pages;

use App\Actions\MoveWorkbookItem;
use App\Enums\WorkbookStatus;
use App\Filament\Resources\Workbook\WorkbookResource;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Js;
use Livewire\Attributes\Computed;
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
     * Every column with its items — ONE query for the whole board, grouped in
     * memory, never a query per column.
     *
     * @return list<array{status: WorkbookStatus, items: Collection<int, WorkbookItem>}>
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
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (WorkbookItem $item): string => $item->status->value);

        return array_map(fn (WorkbookStatus $status): array => [
            'status' => $status,
            'items' => $byStatus->get($status->value, collect()),
        ], WorkbookStatus::columns());
    }

    /**
     * `wire:sort`'s handler. It reports ONE item and its new index, never the
     * whole list — and the third argument is this column's
     * `wire:sort:group-id`, which is how a cross-column drop says where it
     * landed. The id arrives as a STRING.
     */
    public function move(string $item, int $position, string $status): void
    {
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
