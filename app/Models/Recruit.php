<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'espn_id', 'athlete_id', 'recruiting_class', 'display_name', 'grade',
    'national_rank', 'position_rank', 'state_rank', 'status', 'committed_team_id',
    'high_school', 'hometown_city', 'hometown_state', 'position_id',
    'height_in', 'weight_lb', 'analysis',
])]
class Recruit extends Model
{
    public function committedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'committed_team_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function scopeRanked(Builder $query): Builder
    {
        return $query->whereNotNull('national_rank')->orderBy('national_rank');
    }

    public function hometown(): ?string
    {
        return match (true) {
            $this->hometown_city && $this->hometown_state => "{$this->hometown_city}, {$this->hometown_state}",
            (bool) $this->hometown_city => $this->hometown_city,
            default => null,
        };
    }
}
