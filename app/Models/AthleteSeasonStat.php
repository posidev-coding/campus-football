<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['athlete_id', 'season_year', 'season_type', 'team_id', 'category', 'stats', 'display_stats'])]
class AthleteSeasonStat extends Model
{
    protected function casts(): array
    {
        return ['stats' => 'array', 'display_stats' => 'array'];
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
