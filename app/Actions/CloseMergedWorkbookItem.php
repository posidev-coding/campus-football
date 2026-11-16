<?php

namespace App\Actions;

use App\Enums\WorkbookStatus;
use App\Models\WorkbookItem;

/**
 * A merged pull request closes its workbook card — the one path by which
 * something other than a human reaches Done.
 *
 * Two callers reach it, and they must agree on every detail, which is why it
 * is an Action rather than a few lines in a controller:
 *
 *   - `/ops/github`, the merge webhook, when GitHub's delivery lands.
 *   - `cfb:workbook:reconcile`, hourly, for when it did not. GitHub never
 *     retries a failed delivery, and every merge deploys, so a delivery that
 *     arrives mid-swap gets a 503 and the card would sit In review for good —
 *     which is what happened to CFB-121 on 2026-11-16.
 *
 * It matches the STORED `branch` column, which is why that column is unique
 * and never rewritten: no name parsing, no guessing. It moves that one card,
 * with actor `github` and nothing more. **It never records who merged** — every
 * GitHub payload carries a login and an email address, `actor` holds a ROLE,
 * and the no-identity guarantee on the ops reads is one careless argument from
 * being false.
 *
 * Idempotent by construction: `MoveWorkbookItem` writes an event only when the
 * column actually changes, so a redelivery, or a reconcile pass racing a late
 * webhook, moves nothing and says nothing.
 */
class CloseMergedWorkbookItem
{
    public const NOTE = 'Merged.';

    /** Null when no card was ever cut on that branch, which is a normal event. */
    public function handle(string $branch): ?WorkbookItem
    {
        if ($branch === '') {
            return null;
        }

        $item = WorkbookItem::query()->where('branch', $branch)->first();

        if ($item === null) {
            return null;
        }

        app(MoveWorkbookItem::class)->handle(
            $item->id,
            WorkbookStatus::Done,
            actor: WorkbookItem::ACTOR_GITHUB,
            note: self::NOTE,
        );

        return $item;
    }
}
