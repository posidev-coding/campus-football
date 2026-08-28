<?php

namespace App\Models;

use App\Enums\WorkbookLinkType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One directed edge between two issues. Written only by `LinkWorkbookItems`,
 * which is where the two canonicalization rules live.
 *
 * `relation` only ever holds a STORABLE case — `blocks`, `relates_to`,
 * `duplicates`. The inverses are rendered, never stored.
 */
class WorkbookLink extends Model
{
    /** An edge is a fact. There is nothing to update. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'relation' => WorkbookLinkType::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WorkbookItem, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(WorkbookItem::class, 'from_item_id');
    }

    /** @return BelongsTo<WorkbookItem, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(WorkbookItem::class, 'to_item_id');
    }
}
