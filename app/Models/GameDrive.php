<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ESPN's drive chart for one game — every drive, and every play inside it.
 *
 * The single largest thing this application stores: 306 KB per game on
 * average, 600 KB at the worst, 1.4 GB across six seasons. It sat inline on
 * `game_summaries` until measurement showed that table was 86% of the whole
 * database and that the game page's SELECT * was reading all of it on every
 * view to render a box score that never referenced it.
 *
 * Kept rather than dropped because the game page promises it — "Box score,
 * scoring summary and drives" — and re-fetching would cost one 544 KB request
 * per game. On its own table it costs nothing until something asks.
 *
 * So: load it EXPLICITLY, only on the screen that renders it. It has no place
 * in an eager load beside a game or a summary.
 */
#[Fillable(['game_id', 'drives'])]
class GameDrive extends Model
{
    use HasFactory;

    protected $primaryKey = 'game_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'drives' => 'array',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
