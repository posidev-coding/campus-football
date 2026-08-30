<?php

namespace App\Http\Controllers\Ops;

use App\Actions\ClaimWorkbookItem;
use App\Actions\RecordWorkbookEvent;
use App\Actions\ReviewWorkbookItem;
use App\Actions\StartWorkbookItem;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use App\Support\IssueBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The board, for a routine with no database.
 *
 * A local Claude Code session has artisan and git and works through
 * `cfb:issue`; a scheduled cloud routine has neither, so it works through here.
 * Both skins sit on one {@see IssueBoard}, which is what stops a terminal and a
 * routine disagreeing about the state of the board.
 *
 * **What these endpoints cannot do is the specification:**
 *
 *   - **No create.** Filing is `/ops/workbook`, keyed and idempotent. An issue
 *     endpoint that could create would be a second, unkeyed door onto the same
 *     table, and the key is the whole reason a weekly routine does not fill the
 *     board with copies.
 *   - **No dismiss.** Dismissing is a human saying "we know, and no". It is the
 *     one status a machine may never write.
 *   - **No edit of title, body, category, severity, evidence or prompt.** Those
 *     are `propose()`'s. A working agent rewriting its own brief is how a board
 *     stops being trustworthy.
 *   - **No `position`, no delete, and no `done`.** An agent's terminal
 *     transition is In review. If a session could close its own work, In review
 *     is decorative and the trail fills with sessions marking themselves
 *     complete. Merging earns Done.
 *   - **No arbitrary status.** These are named after TRANSITIONS rather than a
 *     `PATCH {status: …}`, which is why they are separate routes: the reachable
 *     set is exactly `planned → in_progress → in_review`, and it is bounded by
 *     the routing table rather than by a validator somebody widens.
 *
 * And no `FeedRun` row, deliberately. A claim is a call, not a pass, and
 * `FeedRun::ADVISOR` describes a whole advisor run. `workbook_events` is
 * already the ledger for what happened to an issue.
 */
class IssueController
{
    /** The fixed-path read, and the only signed route here. */
    public function index(Request $request, IssueBoard $board): JsonResponse
    {
        $data = $request->validate([
            'status' => ['array'], 'status.*' => ['string', 'max:20'],
            'severity' => ['array'], 'severity.*' => ['string', 'max:20'],
            'label' => ['array'], 'label.*' => ['string', 'max:30'],
            'effort' => ['array'], 'effort.*' => ['string', 'max:1'],
            'ready' => ['boolean'],
            'limit' => ['integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'result' => 'ok',
            'issues' => $board->list($data, (int) ($data['limit'] ?? IssueBoard::DEFAULT_LIMIT)),
        ]);
    }

    /**
     * The ready queue, for a client that composes its own URL.
     *
     * A NARROWER `index()` rather than an unsigned copy of it: the ready queue
     * and a limit, and no filter vocabulary at all. `cfb:issue` in remote mode
     * cannot sign a URL — the signature comes off the board's own `APP_KEY`,
     * which a working checkout does not hold — so the signed index is not
     * reachable from a terminal, and this is what a terminal reads instead.
     *
     * Narrowing is the whole point. `signed` was never the authentication here
     * (the token is), so what an unsigned read must not do is WIDEN what a
     * token already reaches, and a queue with no filters cannot.
     */
    public function ready(Request $request, IssueBoard $board): JsonResponse
    {
        $data = $request->validate(['limit' => ['integer', 'min:1', 'max:100']]);

        return response()->json([
            'result' => 'ok',
            'issues' => $board->ready((int) ($data['limit'] ?? IssueBoard::DEFAULT_LIMIT)),
        ]);
    }

    /**
     * One issue, whole — the brief a session works from.
     *
     * The same `IssueBoard::one()` array every write below already returns in
     * its response, minus the write. A token holder could read this by
     * commenting on the issue; being able to read it WITHOUT touching the
     * trail is strictly less than that, which is why a read here is not a new
     * grant and did not need a new guard.
     */
    public function brief(IssueBoard $board, string $issue): JsonResponse
    {
        return $this->ok('ok', $board->one($this->resolve($issue)));
    }

    /**
     * Claim the next ready issue and return it.
     *
     * A POST because it TAKES THE CLAIM — which also collapses list-then-claim
     * into one call, and with it the race between them.
     *
     * `204` when nothing is ready, so a routine branches on a status code
     * rather than parsing a body for an empty list.
     */
    public function next(Request $request, IssueBoard $board): JsonResponse
    {
        $data = $this->actor($request, ['label' => ['array'], 'label.*' => ['string', 'max:30']]);

        $issue = app(ClaimWorkbookItem::class)->next($data['as'], $data['label'] ?? []);

        return $issue === null
            ? response()->json(null, 204)
            : $this->ok('claimed', $board->one($issue));
    }

    public function claim(Request $request, IssueBoard $board, string $issue): JsonResponse
    {
        $item = $this->resolve($issue);
        $data = $this->actor($request);

        $claimed = app(ClaimWorkbookItem::class)->handle($item, $data['as']);

        return $claimed === null ? $this->held($item) : $this->ok('claimed', $board->one($claimed));
    }

    public function release(Request $request, IssueBoard $board, string $issue): JsonResponse
    {
        $item = $this->resolve($issue);
        $data = $this->actor($request);

        $released = app(ClaimWorkbookItem::class)->release($item, $data['as'], $data['note'] ?? null);

        return $released === null ? $this->held($item) : $this->ok('released', $board->one($released));
    }

    public function start(Request $request, IssueBoard $board, string $issue): JsonResponse
    {
        $item = $this->resolve($issue);
        $data = $this->actor($request);

        $started = app(StartWorkbookItem::class)->handle($item, $data['as']);

        return $started === null ? $this->held($item) : $this->ok('started', $board->one($started));
    }

    public function review(Request $request, IssueBoard $board, string $issue): JsonResponse
    {
        $item = $this->resolve($issue);

        $data = $this->actor($request, [
            /*
             * Constrained to the repository's own host. The panel renders this
             * as a link an admin will click, and an unconstrained URL on an
             * admin screen is a phishing surface for free.
             */
            'pr_url' => ['required', 'url', 'max:255', 'starts_with:https://'.config('cfb.repo_host')],
        ]);

        $reviewed = app(ReviewWorkbookItem::class)->handle($item, $data['as'], $data['pr_url'], $data['note'] ?? null);

        return $reviewed === null ? $this->held($item) : $this->ok('in_review', $board->one($reviewed));
    }

    public function comment(Request $request, IssueBoard $board, string $issue): JsonResponse
    {
        $item = $this->resolve($issue);
        $data = $this->actor($request, ['note' => ['required', 'string', 'max:2000']]);

        app(RecordWorkbookEvent::class)->handle($item, WorkbookEvent::COMMENTED, actor: $data['as'], note: $data['note']);

        return $this->ok('commented', $board->one($item->refresh()));
    }

    /**
     * No route-model binding: no unique column holds `CFB-12`, and resolving
     * explicitly means the parser lives in exactly one place — so a bare id and
     * the advisor's key work here for the same reason they work at a terminal.
     */
    private function resolve(string $issue): WorkbookItem
    {
        return WorkbookItem::resolve($issue) ?? abort(404);
    }

    /**
     * Who is calling, and whatever else this route needs.
     *
     * `as` is a ROLE and an instance — 'cloud:nightly'. Never a person: the
     * telemetry snapshot is asserted to carry no user identifiers, and this is
     * the field that would quietly break that.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function actor(Request $request, array $rules = []): array
    {
        return $request->validate([
            'as' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9]*(:[a-z0-9][a-z0-9-]*)?$/'],
            'note' => ['nullable', 'string', 'max:2000'],
            ...$rules,
        ]);
    }

    /** @param  array<string, mixed>  $issue */
    private function ok(string $result, array $issue): JsonResponse
    {
        // The envelope key is `result`, not `status` — the issue keeps its own
        // `status` field, the same way `workbook.open[].status` does in the
        // telemetry snapshot.
        return response()->json(['result' => $result, 'issue' => $issue]);
    }

    /**
     * The double-assign refusal.
     *
     * 409 rather than a 200 carrying `claimed: false`, because a routine backs
     * off on a status code and carries on regardless of a body.
     */
    private function held(WorkbookItem $item): JsonResponse
    {
        $item->refresh();

        return response()->json([
            'result' => 'held',
            'by' => $item->claimed_by,
            'expires_at' => $item->claim_expires_at?->toIso8601String(),
        ], 409);
    }
}
