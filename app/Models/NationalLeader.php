<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's place on a national statistical leaderboard.
 */
#[Fillable([
    'season_year', 'season_type', 'category',
    'athlete_id', 'team_id', 'rank', 'value', 'display_value',
])]
class NationalLeader extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['value' => 'float'];
    }

    /**
     * Deliberately not a foreign key relation with a constraint behind it —
     * ESPN publishes only the CURRENT roster, so a leader from an earlier
     * season may have no athlete row. Callers must tolerate null.
     */
    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Limit to a classification for a season.
     *
     * The feed spans every division — 245 distinct teams in 2025, well beyond
     * the 136 FBS ones — so a leaderboard that does not scope through
     * team_seasons shows FCS players alongside FBS ones with no indication of
     * which is which.
     */
    public function scopeClassification(Builder $query, string $classification): Builder
    {
        return $query->whereIn('team_id', function ($sub) use ($classification) {
            $sub->select('team_id')
                ->from('team_seasons')
                ->whereColumn('team_seasons.season_year', 'national_leaders.season_year')
                ->where('classification', $classification);
        });
    }
}
