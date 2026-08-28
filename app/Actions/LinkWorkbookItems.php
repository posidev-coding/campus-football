<?php

namespace App\Actions;

use App\Enums\WorkbookLinkType;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use App\Models\WorkbookLink;

/**
 * Link two issues, in one direction, once.
 *
 * The whole value of a single-row design is that these two rules hold
 * everywhere, so they live here rather than in a caller:
 *
 *   1. **`blocked_by` and `duplicated_by` are never stored.** `A blocked_by B`
 *      is written as `B blocks A`. Rendering flips it back.
 *   2. **`relates_to` stores with the lower id first.** It is symmetric, so
 *      without this `A relates_to B` and `B relates_to A` are two rows the
 *      unique index happily accepts, and the list renders the same fact twice.
 *
 * Two guards, and no more: nothing links to itself, and `blocks` may not go
 * both ways between one pair. There is deliberately no deep cycle check — a
 * recursive CTE on every write is cost a board of dozens does not earn, and a
 * three-hop cycle is a human problem rather than a data-integrity one.
 */
class LinkWorkbookItems
{
    /** Null means the link was refused; the message says why. */
    public ?string $refusal = null;

    public function handle(
        WorkbookItem $from,
        WorkbookItem $to,
        WorkbookLinkType $relation,
        string $by = WorkbookEvent::ACTOR_HUMAN,
    ): ?WorkbookLink {
        $this->refusal = null;

        if ($from->id === $to->id) {
            return $this->refuse('An issue cannot block, duplicate or relate to itself.');
        }

        // Rule 1: flip an inverse onto the storable side.
        if (! $relation->isStorable()) {
            [$from, $to, $relation] = [$to, $from, $relation->inverse()];
        }

        // Rule 2: symmetric relations get one canonical direction.
        if ($relation === WorkbookLinkType::RelatesTo && $from->id > $to->id) {
            [$from, $to] = [$to, $from];
        }

        if ($relation === WorkbookLinkType::Blocks && $this->exists($to, $from, WorkbookLinkType::Blocks)) {
            return $this->refuse("{$to->reference} already blocks {$from->reference}. Both ways is a deadlock, not a link.");
        }

        $link = WorkbookLink::query()->firstOrCreate([
            'from_item_id' => $from->id,
            'to_item_id' => $to->id,
            'relation' => $relation->value,
        ]);

        // Only a NEW edge is news. Re-running a link should not fill the trail.
        if ($link->wasRecentlyCreated) {
            app(RecordWorkbookEvent::class)->handle(
                $from, WorkbookEvent::LINKED, actor: $by,
                context: ['relation' => $relation->value, 'issue' => $to->reference],
            );
        }

        return $link;
    }

    private function exists(WorkbookItem $from, WorkbookItem $to, WorkbookLinkType $relation): bool
    {
        return WorkbookLink::query()
            ->where('from_item_id', $from->id)
            ->where('to_item_id', $to->id)
            ->where('relation', $relation->value)
            ->exists();
    }

    private function refuse(string $message): null
    {
        $this->refusal = $message;

        return null;
    }
}
