<?php

namespace App\Http\Controllers\Ops;

use App\Actions\MoveWorkbookItem;
use App\Enums\WorkbookStatus;
use App\Models\WorkbookItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The last manual step, automated: a merged pull request closes its issue.
 *
 * This is the ONE path by which something other than a human reaches Done, and
 * it does not weaken the rule — a merge IS the human's answer. A session still
 * cannot close its own work; it can only open the pull request a person then
 * merges.
 *
 * What it does, and nothing more:
 *
 *   - Only `pull_request` events, only `merged: true`. A closed-unmerged pull
 *     request is somebody deciding against the work, and the card stays where
 *     it is for a person to answer.
 *   - Matches `head.ref` against the STORED `branch` column, which is why that
 *     column is unique and never rewritten. No name parsing, no guessing.
 *   - Moves that one issue to Done, actor `github`. It writes no title, no
 *     body, no severity and no labels: this is a merge notification, not an
 *     editor.
 *   - **Never records who merged.** GitHub sends a login and an email address
 *     in every one of these payloads, and `actor` holds a ROLE. The
 *     no-identity guarantee on the ops reads is one careless line from being
 *     false, and this is the payload that would do it.
 *
 * Always 200, even when nothing matched. GitHub retries a non-2xx, and a
 * webhook for a branch this board has never heard of is a normal event rather
 * than a failure.
 */
class GithubController
{
    public function __invoke(Request $request): JsonResponse
    {
        $merged = $request->boolean('pull_request.merged');
        $branch = (string) $request->input('pull_request.head.ref', '');

        if (! $merged || $branch === '') {
            return response()->json(['result' => 'ignored']);
        }

        $item = WorkbookItem::query()->where('branch', $branch)->first();

        if ($item === null) {
            return response()->json(['result' => 'no_issue']);
        }

        /*
         * Idempotent by construction, which matters because GitHub redelivers.
         * `MoveWorkbookItem` writes an event only when the column actually
         * changes, so a second delivery moves nothing and says nothing.
         */
        app(MoveWorkbookItem::class)->handle(
            $item->id,
            WorkbookStatus::Done,
            actor: WorkbookItem::ACTOR_GITHUB,
            note: 'Merged.',
        );

        return response()->json(['result' => 'done', 'issue' => $item->reference]);
    }
}
