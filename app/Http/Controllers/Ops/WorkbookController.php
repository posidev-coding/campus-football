<?php

namespace App\Http\Controllers\Ops;

use App\Enums\WorkbookCategory;
use App\Enums\WorkbookSeverity;
use App\Models\FeedRun;
use App\Models\WorkbookItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The advisor's write door — one pass of the maintenance routine, filed.
 *
 * This is the only endpoint in the application that a machine can write
 * through, so what it CANNOT do is the specification:
 *
 *   - It writes `workbook_items` and one `feed_runs` row. Nothing else. There
 *     is no id in the payload, no status, no position — an item is addressed
 *     only by its `key`, and where it sits on the board is a human's answer,
 *     never the advisor's.
 *   - It cannot reopen a dismissed item. That guard lives in
 *     `WorkbookItem::propose()` rather than here, so it holds for every caller
 *     and not just this one.
 *   - Category and severity are validated against the enums, so the board
 *     cannot grow a vocabulary over HTTP.
 *   - The whole pass is ONE request. A weekly routine filing twelve items
 *     makes one call, which is what lets a single `advisor:review` ledger row
 *     describe the run — and bounds a runaway loop at the throttle rather than
 *     at twelve times the throttle.
 *
 * An `error` instead of `items` records a failed pass. Without it a routine
 * that dies is indistinguishable from one that never ran, and "last run" going
 * quietly stale is the failure mode a ledger exists to prevent.
 */
class WorkbookController
{
    /** One pass may not file more than this. A weekly review is not a firehose. */
    public const MAX_ITEMS = 50;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            // NOT `required_without:error` — Laravel counts an empty array as
            // absent, and a pass that found nothing new is a legitimate pass
            // that still deserves a ledger row. The presence check is below.
            'items' => ['array', 'max:'.self::MAX_ITEMS],
            'items.*.key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'items.*.title' => ['required', 'string', 'max:200'],
            'items.*.body' => ['nullable', 'string', 'max:5000'],
            'items.*.category' => ['required', Rule::enum(WorkbookCategory::class)],
            'items.*.severity' => ['required', Rule::enum(WorkbookSeverity::class)],
            'items.*.evidence' => ['nullable', 'array'],
            'items.*.prompt' => ['nullable', 'string', 'max:10000'],
            'error' => ['nullable', 'string', 'max:2000'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
        ]);

        if (! $request->has('items') && ! isset($data['error'])) {
            throw ValidationException::withMessages([
                'items' => 'Send `items` — an empty array is fine for a pass that found nothing — or an `error`.',
            ]);
        }

        $run = FeedRun::begin(FeedRun::ADVISOR, null);

        if (isset($data['error'])) {
            $run->fail($data['error'], 0, (int) ($data['duration_ms'] ?? 0));

            return response()->json(['status' => 'recorded', 'filed' => 0], 202);
        }

        $filed = [];

        foreach ($data['items'] ?? [] as $item) {
            $row = WorkbookItem::propose($item['key'], [
                'title' => $item['title'],
                'body' => $item['body'] ?? null,
                'category' => WorkbookCategory::from($item['category']),
                'severity' => WorkbookSeverity::from($item['severity']),
                'evidence' => $item['evidence'] ?? null,
                'prompt' => $item['prompt'] ?? null,
                'source' => WorkbookItem::SOURCE_ADVISOR,
            ]);

            // What the advisor is told back is what a human has already
            // decided — so the next run can stop re-proposing it, which is the
            // whole reason the board's state is fed into the prompt.
            $filed[$item['key']] = $row->status->value;
        }

        $run->complete(count($filed), 0, (int) ($data['duration_ms'] ?? 0));

        return response()->json(['status' => 'filed', 'filed' => count($filed), 'items' => $filed]);
    }
}
