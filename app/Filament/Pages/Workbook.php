<?php

namespace App\Filament\Pages;

use App\Actions\MoveWorkbookItem;
use App\Enums\WorkbookStatus;
use App\Filament\Resources\Workbook\WorkbookResource;
use App\Models\WorkbookItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
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

        app(MoveWorkbookItem::class)->handle((int) $item, $column, $position);

        unset($this->columns);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('table')
                ->label('Open the table')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->url(WorkbookResource::getUrl()),
        ];
    }
}
