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
    /**
     * Every saved season row bumps the athlete's denormalized
     * `latest_season_year` — the ONE door, so no sync writer (rosters,
     * stats, seeds, factories) can forget it. Guarded to only move
     * forward: a historical backfill never regresses a current player,
     * and the usual no-op resync fires no save at all.
     */
    protected static function booted(): void
    {
        static::saved(function (self $row) {
            Athlete::whereKey($row->athlete_id)
                ->where(fn ($q) => $q
                    ->whereNull('latest_season_year')
                    ->orWhere('latest_season_year', '<', $row->season_year))
                ->update(['latest_season_year' => $row->season_year]);
        });
    }

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
