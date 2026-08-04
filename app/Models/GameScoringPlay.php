<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'game_id', 'team_id', 'sequence', 'period', 'clock',
    'type', 'abbreviation', 'text', 'home_score', 'away_score',
])]
class GameScoringPlay extends Model
{
    use HasFactory;

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Chronological order.
     *
     * Ordered by our own `sequence`, never by period and clock: a football
     * clock counts DOWN, so ascending clock within a period puts the end of the
     * quarter first and the opening drive last.
     */
    public function scopeInOrder(Builder $query): Builder
    {
        return $query->orderBy('sequence');
    }
}
