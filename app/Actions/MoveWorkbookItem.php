<?php

namespace App\Actions;

use App\Enums\WorkbookStatus;
use App\Models\WorkbookItem;
use Illuminate\Support\Facades\DB;

/**
 * Move one workbook item to a column, at an index.
 *
 * An Action rather than a method on the page, for the reason
 * `ReorderFollowedTeams` is one: this is reachable from a public Livewire
 * method, so the client can send anything — an id that does not exist, a
 * status that is not a column, an index past the end. Every one of those is a
 * quiet no-op or a clamp here, never a partial write.
 *
 * It is also the only layer a test can hold. SortableJS ignores synthetic
 * pointer events, so no interaction test can reproduce a `wire:sort` bug; the
 * drag is proved by asserting the rendered attributes, and the OUTCOME is
 * proved here.
 */
class MoveWorkbookItem
{
    /**
     * @param  int  $position  Sortable's `newIndex`, which is ZERO-BASED —
     *                         verified in livewire.esm.js, and the kind of
     *                         thing that produces an off-by-one rather than an
     *                         error. Stored positions are 1-based.
     */
    public function handle(int $itemId, WorkbookStatus $status, int $position): void
    {
        $item = WorkbookItem::query()->find($itemId);

        if ($item === null) {
            return;
        }

        $from = $item->status;

        DB::transaction(function () use ($item, $status, $position, $from): void {
            $order = WorkbookItem::query()
                ->inColumn($status)
                ->whereKeyNot($item->id)
                ->pluck('id')
                ->all();

            // Clamped rather than trusted: an index past the end lands last.
            array_splice($order, max(0, min($position, count($order))), 0, [$item->id]);

            $item->forceFill(['status' => $status])->save();

            self::renumber($order);

            /*
             * The column it LEFT is renumbered too. Not cosmetic: positions
             * are what the next insert's index is measured against, so gaps
             * left behind accumulate until a drop lands in the wrong place.
             */
            if ($from !== $status) {
                self::renumber(WorkbookItem::query()->inColumn($from)->pluck('id')->all());
            }
        });
    }

    /** @param  list<int>  $ids */
    private static function renumber(array $ids): void
    {
        foreach ($ids as $index => $id) {
            WorkbookItem::query()->whereKey($id)->update(['position' => $index + 1]);
        }
    }
}
