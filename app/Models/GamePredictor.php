<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'game_id', 'game_quality', 'matchup_quality', 'home_projection',
    'away_projection', 'home_opp_strength', 'away_opp_strength', 'synced_at',
])]
class GamePredictor extends Model
{
    protected function casts(): array
    {
        return [
            'game_quality' => 'float',
            'matchup_quality' => 'float',
            'home_projection' => 'float',
            'away_projection' => 'float',
            'synced_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
