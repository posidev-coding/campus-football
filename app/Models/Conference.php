<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

#[Fillable(['id', 'name', 'short_name', 'abbreviation', 'logo', 'is_conference'])]
class Conference extends Model
{
    use HasFactory, Searchable;

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return ['is_conference' => 'boolean'];
    }

    /**
     * Deliberately NOT `abbreviation` — that column holds ESPN's URL slug
     * (`big10`, `mwest`), which nobody types. `short_name` is what a person
     * calls a conference.
     *
     * @return array<string, string|null>
     */
    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'short_name' => $this->short_name,
        ];
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(ConferenceSeason::class);
    }

    /**
     * Members for a given season. Conference membership is season-scoped, so
     * there is deliberately no season-less `teams()` relation to reach for.
     */
    public function teamSeasons(int $seasonYear): HasMany
    {
        return $this->hasMany(TeamSeason::class)->where('season_year', $seasonYear);
    }

    /**
     * The same rows, UNPARAMETERIZED — and it exists for exactly one reason.
     *
     * `withCount()` resolves a relation by calling the method with no
     * arguments, so `teamSeasons(int $year)` cannot feed it: the count would
     * be a TypeError. Callers scope the year in the withCount closure instead:
     *
     *     ->withCount(['memberships as members_count' =>
     *         fn ($q) => $q->where('season_year', $year)])
     *
     * Never read this one unscoped and call the answer a membership list —
     * conference membership is season-scoped, and 513 teams changed conference
     * between 2021 and 2025.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TeamSeason::class);
    }
}
