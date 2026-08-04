<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['coach_id', 'team_id', 'season_year', 'experience', 'wins', 'losses', 'ties'])]
class CoachTeamSeason extends Model
{
    public function coach(): BelongsTo
    {
        return $this->belongsTo(Coach::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * "12-2" for the season, or null before the coach sync has filled the
     * record columns in — a missing attribute reads as null, so this is safe
     * to call ahead of that migration.
     */
    public function record(): ?string
    {
        if ($this->wins === null || $this->losses === null) {
            return null;
        }

        return $this->ties
            ? "{$this->wins}-{$this->losses}-{$this->ties}"
            : "{$this->wins}-{$this->losses}";
    }
}
