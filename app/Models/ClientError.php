<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One JavaScript error, as reported by the browser it happened in. See the
 * migration for why this exists when Pulse already watches the server, and
 * App\Actions\RecordClientError for the Redis dedupe in front of it.
 */
#[Fillable([
    'fingerprint', 'kind', 'message', 'source', 'line', 'col', 'stack',
    'path', 'user_agent', 'viewport', 'standalone', 'reports', 'user_id',
])]
class ClientError extends Model
{
    use Prunable;

    public const ERROR = 'error';

    public const REJECTION = 'unhandledrejection';

    /**
     * A month of history. Long enough for the weekly advisor to see a
     * regression appear and to see it stop, short enough that a bad deploy's
     * noise does not outlive the fix by a season.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(30));
    }

    protected function casts(): array
    {
        return [
            'standalone' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
