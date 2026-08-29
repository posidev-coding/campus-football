<?php

namespace App\Models;

use Database\Factories\TeamSeasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team's conference and classification for one season. This is the table the
 * whole standings fix depends on — see the migration for why.
 */
#[Fillable(['team_id', 'season_year', 'conference_id', 'division_id', 'classification'])]
class TeamSeason extends Model
{
    /** @use HasFactory<TeamSeasonFactory> */
    use HasFactory;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }
}
