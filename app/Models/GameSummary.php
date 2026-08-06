<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The light half of ESPN's summary payload — what a game page renders, plus
 * the freshness bookkeeping the sync turns on.
 *
 * `drives` used to live here and does not any more. It averaged 306 KB a row
 * and made this table 1,764 MB from 4,844 rows, and because the game page
 * loads its summary with a plain `first()` — a SELECT * — every view of every
 * game dragged all of it across the wire to render a box score that never
 * touched it. It lives in `game_drives` now, reachable through drives() when
 * the play-by-play tab finally needs it.
 */
#[Fillable([
    'game_id', 'win_probability', 'leaders',
    'attendance', 'scoring_plays_hash', 'is_final', 'synced_at',
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
     * The drive chart, on its own table.
     *
     * Never eager-load this beside a summary: pulling it back into the same
     * query is exactly the read amplification splitting it out removed.
     */
    public function drives(): HasOne
    {
        return $this->hasOne(GameDrive::class, 'game_id', 'game_id');
    }

    /**
     * Whether this summary needs refreshing from ESPN.
     *
     * A final game's summary can never change, so it is fetched exactly once
     * and every later view is a pure database read. Anything else is due
     * again sixty seconds after its last sync — the one window every
     * dispatcher checks before queueing a fetch, and that FetchGameSummary
     * re-checks before spending a request. Prefer SyncGameSummary::isStale(),
     * which also catches a completed game whose final fetch was swallowed.
     */
    public function isStale(): bool
    {
        if ($this->is_final) {
            return false;
        }

        return $this->synced_at === null || $this->synced_at->lt(now()->subMinute());
    }
}
