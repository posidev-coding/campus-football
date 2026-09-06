<?php

namespace App\Models;

use App\Enums\ViewportBucket;
use Database\Factories\UserDayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person, one league day, and what they touched — the presence table
 * every actives, retention and adoption number is counted out of.
 *
 * A ROW EXISTS ONLY WHEN THEY DID SOMETHING. There is no zero row for a quiet
 * day, and there must never be one: absence is the datum, and a row saying
 * "0 views" is a claim that the app looked and found them not there, which is
 * the same shape of invention as writing a default for missing data.
 * Retention counts rows; stickiness divides one count of rows by another.
 *
 * See App\Enums\ActivityArea and App\Enums\ActivityFeature for the two
 * bitmasks, and the migration for why most of the feature bits are read from
 * the truth tables rather than from the clickstream.
 */
#[Fillable([
    'user_id', 'day', 'views', 'actions', 'areas', 'features',
    'first_seen_at', 'last_seen_at', 'viewport_bucket',
])]
class UserDay extends Model
{
    /** @use HasFactory<UserDayFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'views' => 'integer',
            'actions' => 'integer',
            'areas' => 'integer',
            'features' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'viewport_bucket' => ViewportBucket::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
