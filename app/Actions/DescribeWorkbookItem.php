<?php

namespace App\Actions;

use App\Enums\WorkbookEffort;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;

/**
 * Size an issue and label it — the two human answers a working session is
 * allowed to add to somebody else's card.
 *
 * Labels are ADDED, never replaced: `--label` on a command is one more thing
 * this issue is about, and a session that could clear a human's labels is a
 * session that quietly loses a filter. Clearing them is the panel's job, where
 * a person can see what they are removing.
 *
 * The normalizing happens in the model's mutator, so every path — this, the
 * form, a factory — lands on one vocabulary.
 */
class DescribeWorkbookItem
{
    /** @param  list<string>  $labels */
    public function handle(WorkbookItem $item, string $by, ?WorkbookEffort $effort = null, array $labels = []): WorkbookItem
    {
        if ($effort !== null && $effort !== $item->effort) {
            $item->forceFill(['effort' => $effort])->save();

            app(RecordWorkbookEvent::class)->handle(
                $item, WorkbookEvent::SIZED, actor: $by, context: ['effort' => $effort->value],
            );
        }

        if ($labels === []) {
            return $item;
        }

        $before = $item->labels ?? [];
        $item->labels = [...$before, ...$labels];
        $item->save();

        $added = array_values(array_diff($item->labels ?? [], $before));

        if ($added !== []) {
            app(RecordWorkbookEvent::class)->handle(
                $item, WorkbookEvent::LABELED, actor: $by, context: ['labels_added' => $added],
            );
        }

        return $item;
    }
}
