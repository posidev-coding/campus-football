<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Note the absence of a `$with` property. v3 eager-loaded five relations on
 * every Game by default, which meant a contest listing fanned out badly before
 * a single line of query code was written. Callers ask for what they need.
 */
#[Fillable([
    'id', 'season_id', 'week_id', 'venue_id', 'kickoff_at', 'kickoff_day',
    'name', 'short_name', 'neutral_site', 'conference_game',
    'home_team_id', 'home_score', 'home_rank', 'home_record', 'home_line_scores', 'home_win_prob',
    'away_team_id', 'away_score', 'away_rank', 'away_record', 'away_line_scores', 'away_win_prob',
    'status', 'status_detail', 'period', 'clock', 'completed', 'attendance', 'broadcasts',
])]
class Game extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'kickoff_at' => 'datetime',
            'neutral_site' => 'boolean',
            'conference_game' => 'boolean',
            'completed' => 'boolean',
            'home_line_scores' => 'array',
            'away_line_scores' => 'array',
            'broadcasts' => 'array',
            'home_win_prob' => 'float',
            'away_win_prob' => 'float',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function odds(): HasMany
    {
        return $this->hasMany(GameOdd::class);
    }

    public function predictor(): HasOne
    {
        return $this->hasOne(GamePredictor::class);
    }

    public function summary(): HasOne
    {
        return $this->hasOne(GameSummary::class);
    }

    public function teamStats(): HasMany
    {
        return $this->hasMany(GameTeamStat::class);
    }

    public function scoringPlays(): HasMany
    {
        return $this->hasMany(GameScoringPlay::class);
    }

    public function athleteStats(): HasMany
    {
        return $this->hasMany(AthleteGameStat::class);
    }

    /**
     * Games a contest is allowed to slate.
     *
     * Contests run on a weekly Saturday cadence, so only Saturday kickoffs are
     * eligible. This reads the stored ET day rather than deriving it in SQL —
     * see the migration for why that distinction matters.
     */
    public function scopeSlateEligible(Builder $query): Builder
    {
        return $query->where('kickoff_day', 'Sat');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('completed', true);
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('completed', false)->whereNotNull('status')
            ->whereIn('status', ['in', 'halftime', 'end-period']);
    }

    public function winnerTeamId(): ?int
    {
        if (! $this->completed || $this->home_score === $this->away_score) {
            return null;
        }

        return $this->home_score > $this->away_score
            ? $this->home_team_id
            : $this->away_team_id;
    }

    public function isTie(): bool
    {
        return $this->completed && $this->home_score === $this->away_score;
    }

    public function opponentOf(int $teamId): ?int
    {
        return match ($teamId) {
            $this->home_team_id => $this->away_team_id,
            $this->away_team_id => $this->home_team_id,
            default => null,
        };
    }
}
