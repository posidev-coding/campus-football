<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * As with Team, there is deliberately no `team_id` and no `team()` relation.
 * A player's team is a fact about a season — ask for it with a year.
 */
#[Fillable([
    'id', 'slug', 'first_name', 'last_name', 'display_name', 'short_name',
    'headshot_url', 'height_in', 'display_height', 'weight_lb', 'display_weight',
    'birth_city', 'birth_state', 'birth_country', 'is_active',
])]
class Athlete extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(AthleteTeamSeason::class);
    }

    public function seasonFor(int $year): HasOne
    {
        return $this->hasOne(AthleteTeamSeason::class)->where('season_year', $year);
    }

    /** Most recent team assignment — what a player page headers with. */
    public function latestSeason(): HasOne
    {
        return $this->hasOne(AthleteTeamSeason::class)->latestOfMany('season_year');
    }

    public function seasonStats(): HasMany
    {
        return $this->hasMany(AthleteSeasonStat::class);
    }

    public function gameStats(): HasMany
    {
        return $this->hasMany(AthleteGameStat::class);
    }

    public function injuries(): HasMany
    {
        return $this->hasMany(Injury::class);
    }

    public function hometown(): ?string
    {
        return match (true) {
            $this->birth_city && $this->birth_state => "{$this->birth_city}, {$this->birth_state}",
            (bool) $this->birth_city => $this->birth_city,
            default => null,
        };
    }
}
