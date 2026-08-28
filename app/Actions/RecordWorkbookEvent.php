<?php

namespace App\Actions;

use App\Enums\WorkbookStatus;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;

/**
 * Write one line into an issue's activity trail.
 *
 * The only class that inserts into `workbook_events`, the same shape as
 * `GrantWalletEntry` being the only wallet writer — because the value of a
 * trail is entirely in its completeness. Five things wrote `status` before this
 * existed and four recorded nothing; a record with four holes in it is worse
 * than none, since a reader believes it.
 *
 * Nothing here throws on a long actor or a long note. A bookkeeping write that
 * can fail the operation it is bookkeeping for is the wrong trade — the same
 * reasoning that makes RecordUxEvent swallow.
 */
class RecordWorkbookEvent
{
    /** @param  array<string, mixed>|null  $context */
    public function handle(
        WorkbookItem $item,
        string $kind,
        ?WorkbookStatus $from = null,
        ?WorkbookStatus $to = null,
        string $actor = WorkbookEvent::ACTOR_HUMAN,
        ?string $note = null,
        ?array $context = null,
    ): WorkbookEvent {
        return $item->events()->create([
            'kind' => $kind,
            'from_status' => $from,
            'to_status' => $to,
            'actor' => mb_substr($actor, 0, WorkbookEvent::ACTOR_MAX_LENGTH),
            'note' => $note,
            // Null, never `[]` — an empty context is no data, and a reader
            // skips it rather than rendering an empty object.
            'context' => $context === [] ? null : $context,
        ]);
    }
}
