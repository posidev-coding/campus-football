<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Searchable;

#[Fillable([
    'espn_id', 'athlete_id', 'espn_athlete_id', 'recruiting_class', 'display_name',
    'first_name', 'last_name', 'grade', 'national_rank', 'position_rank',
    'state_rank', 'region_rank', 'status', 'committed_team_id', 'high_school',
    'hometown_city', 'hometown_state', 'position_id', 'height_in', 'weight_lb',
])]
class Recruit extends Model
{
    use Searchable;

    /**
     * A prefix, like Athlete and for the same reason: 27,178 prospects across
     * eight classes is the same order of magnitude as the athletes table, and a
     * prefix rides a btree where a contains-LIKE would scan on every keystroke.
     *
     * BOTH halves of the name, also like Athlete. A prefix matches from the
     * start of a field, so indexing `display_name` alone means a surname finds
     * nobody — "Brewster" returned nothing for "Jalen Brewster" until this
     * carried `last_name` too.
     *
     * @return array<string, mixed>
     */
    #[SearchUsingPrefix(['display_name', 'last_name'])]
    public function toSearchableArray(): array
    {
        return [
            'display_name' => $this->display_name,
            'last_name' => $this->last_name,
        ];
    }

    public function committedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'committed_team_id');
    }

    /**
     * Every school in contention — not just the commitment.
     *
     * ESPN calls the date `visit`, but only 6% of entries carry one, so this is
     * an interest list with occasional visits attached.
     */
    public function schools(): HasMany
    {
        return $this->hasMany(RecruitSchool::class);
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
