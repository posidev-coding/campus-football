<?php

namespace App\Models;

use App\Enums\WorkbookCategory;
use App\Enums\WorkbookSeverity;
use App\Enums\WorkbookStatus;
use Database\Factories\WorkbookItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One piece of proposed work. See the migration for why `key` is the whole
 * idempotency story.
 */
#[Fillable([
    'key', 'title', 'body', 'category', 'severity', 'status',
    'evidence', 'prompt', 'source', 'first_seen_at', 'last_seen_at', 'position',
])]
class WorkbookItem extends Model
{
    /** @use HasFactory<WorkbookItemFactory> */
    use HasFactory;

    public const SOURCE_ADVISOR = 'advisor';

    public const SOURCE_HUMAN = 'human';

    /**
     * Mirrors the column defaults, and it is not redundant with them.
     *
     * A database default fills the ROW but not the in-memory model, so
     * `create([...])->status` read null until something refetched it — a
     * cast-to-enum property that is null on the object and correct in MySQL is
     * the kind of gap a Filament badge renders blank over.
     */
    protected $attributes = [
        'status' => WorkbookStatus::Inbox->value,
        'source' => self::SOURCE_ADVISOR,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'category' => WorkbookCategory::class,
            'severity' => WorkbookSeverity::class,
            'status' => WorkbookStatus::class,
            'evidence' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * File an item, or refresh the one already filed under this key.
     *
     * THE ONE DOORWAY for the advisor, so the two rules cannot be forgotten by
     * a new caller — the same reason every wallet write goes through
     * GrantWalletEntry:
     *
     *   1. `key` is unique, so a weekly routine re-proposing the same finding
     *      updates one row instead of filing a five-hundredth copy.
     *   2. **A dismissed item is never resurrected.** Dismissing is how a human
     *      says "we know, and no". `last_seen_at` still moves — the finding IS
     *      still true, and knowing it recurred is worth having — but nothing
     *      else is touched and the status stays dismissed.
     *
     * A human's edits to title, body or severity survive a re-propose in one
     * direction only: the advisor owns the evidence and the prompt, because
     * those are what it just recomputed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function propose(string $key, array $attributes): self
    {
        $existing = static::query()->where('key', $key)->first();

        if ($existing?->status === WorkbookStatus::Dismissed) {
            // Touched, not reopened. The recurrence is a fact worth recording
            // even though the decision stands.
            $existing->forceFill(['last_seen_at' => now()])->save();

            return $existing;
        }

        if ($existing === null) {
            $status = $attributes['status'] ?? WorkbookStatus::Inbox;

            return static::create([
                ...$attributes,
                'key' => $key,
                'status' => $status,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'position' => static::nextPosition($status),
            ]);
        }

        // first_seen_at is deliberately NOT in this list: it answers "how long
        // has this been true", which is the most useful number on the card and
        // the one a re-propose would quietly reset to today.
        $existing->fill([...$attributes, 'last_seen_at' => now()])->save();

        return $existing;
    }

    /** The end of a column. Positions only ever compare against siblings. */
    public static function nextPosition(WorkbookStatus|string $status): int
    {
        $value = $status instanceof WorkbookStatus ? $status->value : $status;

        return (int) static::query()->where('status', $value)->max('position') + 1;
    }

    /** One column of the board, in order. */
    public function scopeInColumn(Builder $query, WorkbookStatus $status): Builder
    {
        return $query->where('status', $status->value)->orderBy('position')->orderBy('id');
    }

    /** Everything a human has not already answered. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [WorkbookStatus::Done->value, WorkbookStatus::Dismissed->value]);
    }
}
