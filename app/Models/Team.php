<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Note what is absent: there is no `conference_id` column and no `conference()`
 * relation. Conference membership is a fact about a team *in a season*, so it
 * lives on TeamSeason and must be asked for with a year.
 */
#[Fillable([
    'id', 'slug', 'location', 'name', 'nickname', 'abbreviation',
    'display_name', 'short_display_name', 'color', 'alt_color', 'logo', 'logo_dark',
])]
class Team extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'int';

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(TeamSeason::class);
    }

    public function seasonFor(int $year): HasOne
    {
        return $this->hasOne(TeamSeason::class)->where('season_year', $year);
    }

    public function standings(): HasMany
    {
        return $this->hasMany(Standing::class);
    }

    public function homeGames(): HasMany
    {
        return $this->hasMany(Game::class, 'home_team_id');
    }

    public function awayGames(): HasMany
    {
        return $this->hasMany(Game::class, 'away_team_id');
    }

    public function rankings(): HasMany
    {
        return $this->hasMany(Ranking::class);
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_follows')->withTimestamps();
    }

    /**
     * Every game this team played, home or away.
     *
     * Home and away are separate denormalized columns on `games` — that is what
     * keeps the scoreboard join-free — so a team's schedule is a union rather
     * than a single relation, and the two scopes above cannot express it.
     */
    public function games(): Builder
    {
        return Game::query()->where(fn ($q) => $q
            ->where('home_team_id', $this->id)
            ->orWhere('away_team_id', $this->id));
    }

    /**
     * Hex colors arrive from ESPN without a leading `#`.
     */
    public function accentColor(): ?string
    {
        return $this->color ? '#'.ltrim($this->color, '#') : null;
    }
}
