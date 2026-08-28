<?php

namespace App\Support;

use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The board, as data — one object, two skins.
 *
 * `cfb:issue --json` at a terminal and `/ops/issues` over HTTP serve these
 * arrays verbatim, the same "one object, two surfaces" shape as
 * `CoverageReport` feeding `cfb:doctor` and the DataCoverage widget, and
 * `TelemetrySnapshot` feeding `cfb:telemetry` and `/ops/telemetry`. A local
 * session and a cloud routine must not be able to disagree about the board.
 *
 * `null` is carried through, never softened to `''` or `[]`. A field with no
 * data says so, and the consumer skips it.
 */
class IssueBoard
{
    /** How much trail one issue's payload carries. The tail is not the story. */
    public const TRAIL_LIMIT = 20;

    public const DEFAULT_LIMIT = 25;

    /**
     * Everything about one issue — the brief a session works from.
     *
     * @return array<string, mixed>
     */
    public function one(WorkbookItem $item): array
    {
        return [
            ...$this->row($item),
            'body' => $item->body,
            'prompt' => $item->prompt,
            'source' => $item->source,
            'pr_url' => $item->pr_url,
            'first_seen_at' => $item->first_seen_at?->toIso8601String(),
            'last_seen_at' => $item->last_seen_at?->toIso8601String(),
            'started_at' => $item->started_at?->toIso8601String(),
            'completed_at' => $item->completed_at?->toIso8601String(),
            'evidence' => $item->evidence,
            'trail' => $this->trail($item),
        ];
    }

    /**
     * The ready queue, worst first — what an agent may start without asking
     * anybody a question.
     *
     * @return list<array<string, mixed>>
     */
    public function ready(int $limit = self::DEFAULT_LIMIT): array
    {
        return $this->list(['ready' => true, 'status' => ['planned']], $limit);
    }

    /**
     * The board, filtered.
     *
     * @param  array<string, mixed>  $filters  status, severity, label, effort, ready, mine
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = [], int $limit = self::DEFAULT_LIMIT): array
    {
        $labels = array_values(array_filter(array_map(
            fn (string $label): string => Str::slug($label),
            (array) ($filters['label'] ?? []),
        )));

        return WorkbookItem::query()
            ->when($filters['status'] ?? [], fn (Builder $q, array $s): Builder => $q->whereIn('status', $s))
            ->when($filters['severity'] ?? [], fn (Builder $q, array $s): Builder => $q->whereIn('severity', $s))
            ->when($filters['effort'] ?? [], fn (Builder $q, array $e): Builder => $q->whereIn('effort', $e))
            ->when($filters['ready'] ?? false, fn (Builder $q): Builder => $q->whereNotNull('ready_at'))
            // "What am I holding" — a LIVE claim, so a lapsed lease drops off
            // the list rather than looking like work in hand.
            ->when($filters['mine'] ?? null, fn (Builder $q, string $by): Builder => $q
                ->where('claimed_by', $by)
                ->where(fn (Builder $inner): Builder => $inner
                    ->whereNull('claim_expires_at')
                    ->orWhere('claim_expires_at', '>=', now())))
            ->when($labels !== [], fn (Builder $q): Builder => $q->where(function (Builder $q) use ($labels): void {
                foreach ($labels as $label) {
                    $q->orWhereJsonContains('labels', $label);
                }
            }))
            // `FIELD()`, because the column holds the enum's string value and
            // alphabetically that is critical-high-low-medium.
            ->orderByRaw("field(severity, 'critical', 'high', 'medium', 'low')")
            ->orderBy('position')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (WorkbookItem $item): array => $this->row($item))
            ->all();
    }

    /**
     * One line of the board — enough to choose from, never enough to work from.
     *
     * @return array<string, mixed>
     */
    private function row(WorkbookItem $item): array
    {
        return [
            'reference' => $item->reference,
            'key' => $item->key,
            'title' => $item->title,
            'category' => $item->category->value,
            'severity' => $item->severity->value,
            'effort' => $item->effort?->value,
            'labels' => $item->labels,
            'status' => $item->status->value,
            'branch' => $item->branch,
            'ready_at' => $item->ready_at?->toIso8601String(),
            'claim' => $item->isHeld() ? [
                'by' => $item->claimed_by,
                'at' => $item->claimed_at?->toIso8601String(),
                'expires_at' => $item->claim_expires_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * The last few things that happened, newest last, so it reads like a story.
     *
     * @return list<array<string, mixed>>
     */
    private function trail(WorkbookItem $item): array
    {
        return $item->events()
            ->latest('created_at')
            ->latest('id')
            ->limit(self::TRAIL_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (WorkbookEvent $event): array => [
                'kind' => $event->kind,
                'from' => $event->from_status?->value,
                'to' => $event->to_status?->value,
                'actor' => $event->actor,
                'note' => $event->note,
                'context' => $event->context,
                'at' => $event->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
