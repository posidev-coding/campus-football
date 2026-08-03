<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's team for one season. Same season-scoping as TeamSeason, and for
 * the same reason — a transfer must not rewrite history.
 */
#[Fillable([
    'athlete_id', 'team_id', 'season_year', 'jersey',
    'position_id', 'position_group', 'experience_class', 'status',
])]
class AthleteTeamSeason extends Model
{
    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
