<?php

namespace App\Actions;

use App\Enums\WorkbookStatus;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use Illuminate\Support\Facades\DB;

/**
 * Move one workbook item to a column, at an index — and the ONE DOORWAY every
 * status write goes through.
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
 *
 * And it is where the lifecycle stamps live, because they are facts about a
 * TRANSITION and nothing else can see one: `started_at` on the first entry into
 * In progress, `completed_at` on Done, and the claim released the moment an
 * issue is back in a queue or finished.
 */
class MoveWorkbookItem
{
    /**
     * @param  int|null  $position  Sortable's `newIndex`, which is ZERO-BASED —
     *                              verified in livewire.esm.js, and the kind of
     *                              thing that produces an off-by-one rather than
     *                              an error. Stored positions are 1-based.
     *                              NULL means APPEND, and it is not the same as
     *                              0: zero is the TOP of a column, which would
     *                              silently reverse a bulk move's order.
     * @param  string|null  $note  One line for the trail, from the human who moved it.
     */
    public function handle(
        int $itemId,
        WorkbookStatus $status,
        ?int $position = null,
        string $actor = WorkbookEvent::ACTOR_HUMAN,
        ?string $note = null,
    ): ?WorkbookItem {
        $item = WorkbookItem::query()->find($itemId);

        if ($item === null) {
            return null;
        }

        $from = $item->status;

        DB::transaction(function () use ($item, $status, $position, $from, $actor, $note): void {
            $order = WorkbookItem::query()
                ->inColumn($status)
                ->whereKeyNot($item->id)
                ->pluck('id')
                ->all();

            // Clamped rather than trusted: an index past the end lands last.
            $index = $position === null ? count($order) : max(0, min($position, count($order)));

            array_splice($order, $index, 0, [$item->id]);

            $item->forceFill(self::stamps($item, $status))->save();

            self::renumber($order);

            /*
             * The column it LEFT is renumbered too. Not cosmetic: positions
             * are what the next insert's index is measured against, so gaps
             * left behind accumulate until a drop lands in the wrong place.
             */
            if ($from !== $status) {
                self::renumber(WorkbookItem::query()->inColumn($from)->pluck('id')->all());

                /*
                 * ...and only a CHANGE OF COLUMN is activity. A reorder inside
                 * one is housekeeping, and a board where every nudge writes a
                 * row is a trail nobody opens — which costs the trail the only
                 * thing it has.
                 */
                app(RecordWorkbookEvent::class)->handle(
                    $item, WorkbookEvent::MOVED, from: $from, to: $status, actor: $actor, note: $note,
                );
            }
        });

        return $item;
    }

    /**
     * What a transition stamps, beyond the column itself.
     *
     * @return array<string, mixed>
     */
    private static function stamps(WorkbookItem $item, WorkbookStatus $status): array
    {
        $stamps = ['status' => $status];

        // FIRST entry only. Bouncing back through In progress a second time
        // must not reset how long this has been being worked on.
        if ($status === WorkbookStatus::InProgress && $item->started_at === null) {
            $stamps['started_at'] = now();
        }

        if ($status === WorkbookStatus::Done) {
            $stamps['completed_at'] = now();
        }

        // A claim is a lease on work in flight. Finished, or back in a queue,
        // and it belongs to nobody — otherwise a released issue stays held
        // until its lease happens to lapse.
        if (in_array($status, [WorkbookStatus::Done, WorkbookStatus::Inbox, WorkbookStatus::Planned], true)) {
            $stamps += ['claimed_at' => null, 'claimed_by' => null, 'claim_expires_at' => null];
        }

        return $stamps;
    }

    /** @param  list<int>  $ids */
    private static function renumber(array $ids): void
    {
        foreach ($ids as $index => $id) {
            WorkbookItem::query()->whereKey($id)->update(['position' => $index + 1]);
        }
    }
}
