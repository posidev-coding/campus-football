<?php

namespace App\Actions;

use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;

/**
 * Mark an issue ready for an agent to start.
 *
 * `ready_at` is a different fact from `status = planned` and it earns its own
 * column for one reason: planned means *we intend to do this*, ready means *the
 * brief is complete enough that an agent can start without asking a human a
 * question*. Conflating them is how a half-written card gets claimed by a cloud
 * routine at 3am and worked on from a title.
 *
 * Idempotent: readying something already ready moves nothing and says nothing.
 */
class ReadyWorkbookItem
{
    public function handle(WorkbookItem $item, string $by, ?string $note = null): WorkbookItem
    {
        if ($item->ready_at !== null) {
            return $item;
        }

        $item->forceFill(['ready_at' => now()])->save();

        app(RecordWorkbookEvent::class)->handle($item, WorkbookEvent::READIED, actor: $by, note: $note);

        return $item;
    }
}
