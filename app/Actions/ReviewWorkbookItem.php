<?php

namespace App\Actions;

use App\Enums\WorkbookStatus;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use Illuminate\Support\Facades\DB;

/**
 * A session's terminal transition: the pull request is open, the card lands in
 * In review, the claim goes back.
 *
 * This is as far as an agent can ever get. If a session could reach Done, In
 * review is decorative and the trail fills with sessions marking their own work
 * complete. **Merging earns Done**, and merging is a human's.
 *
 * The claim is released HERE rather than inside `MoveWorkbookItem`, which
 * clears it only on Done, Inbox and Planned — the three columns where an issue
 * is unambiguously finished or back in a queue. In review is neither; it is
 * work handed on, and only the hander knows it is done handing.
 */
class ReviewWorkbookItem
{
    /**
     * What every doorway agrees a pull request URL is.
     *
     * `cfb:issue review --pr=` and the panel's Review action both validate on
     * these two, so a URL one accepts is a URL the other accepts. Two hand-
     * written copies of the same rule is how the terminal and the board come
     * to disagree about the same string.
     */
    public const URL_SCHEME = 'https://';

    public const URL_MAX_LENGTH = 255;

    /** Null means somebody else holds this issue. Never take their work. */
    public function handle(WorkbookItem $item, string $by, string $prUrl, ?string $note = null): ?WorkbookItem
    {
        if ($item->heldByAnother($by)) {
            return null;
        }

        return DB::transaction(function () use ($item, $by, $prUrl, $note): WorkbookItem {
            $item->forceFill(['pr_url' => $prUrl])->save();

            app(RecordWorkbookEvent::class)->handle(
                $item, WorkbookEvent::PR_OPENED, actor: $by, note: $note, context: ['pr_url' => $prUrl],
            );

            app(MoveWorkbookItem::class)->handle($item->id, WorkbookStatus::InReview, actor: $by);

            $item->refresh();

            if ($item->claimed_at !== null) {
                app(ClaimWorkbookItem::class)->release($item, (string) $item->claimed_by);
            }

            return $item->refresh();
        });
    }
}
