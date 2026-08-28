<?php

namespace App\Actions;

use App\Enums\WorkbookStatus;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use Illuminate\Support\Facades\DB;

/**
 * Begin work on an issue: take the claim, mint the branch, move the card.
 *
 * One transaction, because a claim without a branch is an issue nobody can find
 * their way back to, and a branch without a claim is two sessions on one problem.
 *
 * It does NOT run git. A command that reaches into the working tree is one that
 * will one day do it on the wrong branch — the `AdvisorSetupCommand` rule,
 * "prints, never writes", applied to a repository. The caller prints the
 * `git switch -c` line and a human or a session runs it.
 *
 * Idempotent for the holder: running it again on an issue you already hold
 * renews the lease and re-reads the same branch. The branch is minted ONCE and
 * never rewritten, because it is the durable copy of the reference and it is
 * already in git.
 */
class StartWorkbookItem
{
    /** Null means somebody else holds it. Never steal a claim. */
    public function handle(WorkbookItem $item, string $by): ?WorkbookItem
    {
        return DB::transaction(function () use ($item, $by): ?WorkbookItem {
            if (! app(ClaimWorkbookItem::class)->take($item, $by)) {
                return null;
            }

            $item->refresh();

            $fresh = $item->branch === null;

            if ($fresh) {
                $item->forceFill(['branch' => $item->branchName()])->save();
            }

            if ($item->status !== WorkbookStatus::InProgress) {
                app(MoveWorkbookItem::class)->handle($item->id, WorkbookStatus::InProgress, actor: $by);
                $item->refresh();
            }

            // Only the first start is news. Re-running to re-read the branch
            // should not write a row saying so.
            if ($fresh) {
                app(RecordWorkbookEvent::class)->handle(
                    $item, WorkbookEvent::STARTED, actor: $by, context: ['branch' => $item->branch],
                );
            }

            return $item;
        });
    }
}
