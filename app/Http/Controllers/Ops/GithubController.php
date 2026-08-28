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
        $payload = self::payload($request);

        $merged = filter_var(data_get($payload, 'pull_request.merged'), FILTER_VALIDATE_BOOLEAN);
        $branch = (string) data_get($payload, 'pull_request.head.ref', '');

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

    /**
     * GitHub sends the same JSON two ways, and the second one used to be a
     * silent no-op.
     *
     * The "Add webhook" form DEFAULTS to `application/x-www-form-urlencoded`,
     * which posts `payload=<urlencoded json>` rather than a JSON body. The HMAC
     * verifies either way — it is computed over the RAW body, whatever shape
     * that body is — so the signature was never the problem. Reading only
     * `$request->input('pull_request.merged')` was: on a form body it is null,
     * so a correctly-signed merge answered 200 `ignored`, moved nothing, and
     * showed a GREEN CHECKMARK in GitHub's delivery log. Verified 2026-08-28.
     *
     * Accepting both rather than rejecting the form shape, deliberately: it is
     * GitHub's own default, so it is a likely configuration rather than a
     * mistake, and there is no security difference between the two. A 422
     * would make the misconfiguration loud, but it would also mean a webhook
     * set up through the UI in the obvious way simply does not work.
     *
     * @return array<string, mixed>
     */
    private static function payload(Request $request): array
    {
        if (! $request->isJson() && is_string($encoded = $request->input('payload'))) {
            // Malformed JSON decodes to null, which casts to an empty array —
            // and an empty array is not a merge, so it falls through to
            // `ignored` rather than throwing on a body we did not write.
            return (array) json_decode($encoded, true);
        }

        return $request->all();
    }
}
