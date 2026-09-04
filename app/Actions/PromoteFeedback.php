<?php

namespace App\Actions;

use App\Enums\WorkbookCategory;
use App\Enums\WorkbookSeverity;
use App\Filament\Resources\Workbook\WorkbookResource;
use App\Models\Feedback;
use App\Models\WorkbookItem;
use Illuminate\Support\Facades\DB;

/**
 * A note becomes a card.
 *
 * Through {@see WorkbookResource::fileAsHuman()} rather than beside it — that
 * is the human doorway onto the board, where the key, the source and the
 * end-of-column position are decided once, and a second filing path is how
 * one of those gets forgotten. Filed as `human` because it IS a human's
 * decision: the reader wrote a note, the admin decided it was work.
 *
 * THE IDENTITY BOUNDARY. Open workbook titles reach the advisor through
 * TelemetrySnapshot, so the admin owns the title (it is a form field, not a
 * derivation) and the body carries what the reader SAID and where they were —
 * never who they are. No name, no email, no handle crosses here; the feedback
 * row keeps the link to its author, and that row never leaves the panel.
 *
 * One transaction: a card without the link back is a note that looks
 * unanswered forever, and a link to a card that failed to file is worse.
 */
class PromoteFeedback
{
    public function handle(Feedback $feedback, string $title, WorkbookCategory $category, WorkbookSeverity $severity): WorkbookItem
    {
        return DB::transaction(function () use ($feedback, $title, $category, $severity): WorkbookItem {
            $item = WorkbookResource::fileAsHuman([
                'title' => $title,
                'category' => $category,
                'severity' => $severity,
                'body' => $this->body($feedback),
            ]);

            $feedback->forceFill([
                'workbook_item_id' => $item->id,
                'handled_at' => $feedback->handled_at ?? now(),
            ])->save();

            return $item;
        });
    }

    /**
     * The note and its context, and nothing about its author.
     *
     * A context line is skipped when we hold nothing for it — "Release: —"
     * is a default wearing a label, and null means no data.
     */
    private function body(Feedback $feedback): string
    {
        $context = array_filter([
            'Kind' => $feedback->kind->label(),
            'Page' => $feedback->path,
            'Release' => $feedback->release,
            'Viewport' => $feedback->viewport === null ? null : $feedback->viewport.'px'.($feedback->standalone ? ', installed' : ''),
            'Sent' => $feedback->created_at?->toDateTimeString(),
        ], fn (?string $value): bool => $value !== null && $value !== '');

        $lines = array_map(
            fn (string $label, string $value): string => "{$label}: {$value}",
            array_keys($context),
            array_values($context),
        );

        return trim($feedback->body)."\n\n".implode("\n", $lines);
    }
}
