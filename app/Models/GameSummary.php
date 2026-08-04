<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The parts of ESPN's summary payload that are only ever rendered whole.
 */
#[Fillable([
    'game_id', 'drives', 'win_probability', 'leaders',
    'attendance', 'is_final', 'synced_at',
])]
class GameSummary extends Model
{
    use HasFactory;

    protected $primaryKey = 'game_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'drives' => 'array',
            'win_probability' => 'array',
            'leaders' => 'array',
            'is_final' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Whether this summary needs refreshing from ESPN.
     *
     * A final game's summary can never change, so it is fetched exactly once
     * and every later view is a pure database read. Anything else is refetched
     * at most once a minute — the throttle lives in SyncGameSummary, and this
     * is only the cheap short-circuit before it.
     */
    public function isStale(): bool
    {
        if ($this->is_final) {
            return false;
        }

        return $this->synced_at === null || $this->synced_at->lt(now()->subMinute());
    }
}
