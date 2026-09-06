<?php

namespace App\Models;

use App\Enums\ActivityKind;
use Database\Factories\ActivityEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened, with a person attached. See the migration for why
 * the raw stream lives thirty days and the rollups live forever, and
 * App\Actions\RecordActivity for the Redis stream in front of it.
 *
 * No `created_at`: `occurred_at` is when it happened, and a second timestamp
 * recording when the drain got round to writing it would be a number that
 * looks like the first and is not.
 */
#[Fillable([
    'stream_id', 'kind', 'user_id', 'visitor', 'audience', 'route', 'facet',
    'subject_type', 'subject_id', 'occurred_at', 'day', 'hour', 'viewport',
    'standalone', 'via_navigate', 'release',
])]
class ActivityEvent extends Model
{
    /** @use HasFactory<ActivityEventFactory> */
    use HasFactory;

    use Prunable;

    /**
     * Thirty days, matching `ClientError` — so a JavaScript error can be read
     * against the traffic that produced it for as long as the error row
     * itself survives. It is also long enough to re-derive every rollup after
     * a rollup bug without a backfill, and short enough that the one table
     * carrying identity has a ceiling nobody has to remember.
     */
    public const KEEP_DAYS = 30;

    /** 0 guest, 1 member, 2 staff. Recorded at request time. */
    public const GUEST = 0;

    public const MEMBER = 1;

    public const STAFF = 2;

    public $timestamps = false;

    public function prunable(): Builder
    {
        return static::where('occurred_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    protected function casts(): array
    {
        return [
            'kind' => ActivityKind::class,
            'occurred_at' => 'datetime',
            'day' => 'date',
            'hour' => 'integer',
            'audience' => 'integer',
            'viewport' => 'integer',
            'standalone' => 'boolean',
            'via_navigate' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
