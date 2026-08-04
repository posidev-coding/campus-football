<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Scout\Searchable;

#[Fillable(['id', 'first_name', 'last_name', 'display_name'])]
class Coach extends Model
{
    use Searchable;

    public $incrementing = false;

    protected $keyType = 'int';

    public function seasons(): HasMany
    {
        return $this->hasMany(CoachTeamSeason::class);
    }

    /** Most recent team — what a search row and a coach page header with. */
    public function latestSeason(): HasOne
    {
        return $this->hasOne(CoachTeamSeason::class)->latestOfMany('season_year');
    }

    /**
     * Contains-LIKE across a table that is 136 rows today and a few thousand
     * once historical staffs sync — never worth an index.
     *
     * @return array<string, string|null>
     */
    public function toSearchableArray(): array
    {
        return [
            'display_name' => $this->display_name,
            'last_name' => $this->last_name,
        ];
    }

    /**
     * "Montgomery, AL" — same shape as Athlete::hometown(), and null-safe
     * against the columns not existing yet: birthplaces arrive with the coach
     * sync, and a missing attribute reads as null rather than throwing.
     */
    public function hometown(): ?string
    {
        return match (true) {
            $this->birth_city && $this->birth_state => "{$this->birth_city}, {$this->birth_state}",
            (bool) $this->birth_city => $this->birth_city,
            default => null,
        };
    }

    /** "117-21", or "117-21-1" when a tie exists. Null until the sync runs. */
    public function careerRecord(): ?string
    {
        if ($this->career_wins === null || $this->career_losses === null) {
            return null;
        }

        return $this->career_ties
            ? "{$this->career_wins}-{$this->career_losses}-{$this->career_ties}"
            : "{$this->career_wins}-{$this->career_losses}";
    }
}
