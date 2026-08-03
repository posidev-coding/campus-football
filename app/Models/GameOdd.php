<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'game_id', 'provider_id', 'provider', 'phase', 'spread', 'over_under',
    'moneyline_home', 'moneyline_away', 'favorite_team_id', 'details', 'captured_at',
])]
class GameOdd extends Model
{
    public const OPEN = 'open';

    public const CURRENT = 'current';

    public const CLOSE = 'close';

    protected function casts(): array
    {
        return [
            'spread' => 'float',
            'over_under' => 'float',
            'captured_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function favorite(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'favorite_team_id');
    }
}
