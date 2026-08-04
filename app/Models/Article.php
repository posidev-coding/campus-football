<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'espn_id', 'headline', 'description', 'byline', 'type',
    'image_url', 'url', 'premium', 'published_at',
])]
class Article extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'premium' => 'boolean',
        ];
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    public function scopeNewest(Builder $query): Builder
    {
        return $query->orderByDesc('published_at');
    }

    /**
     * Articles mentioning a team.
     *
     * Note this is not the same set as ESPN's own team feed — a national Top 25
     * preview tags 25 teams, so tag-matching alone surfaces the same listicles
     * on every team's page. The team News tab reads the dedicated `team=` feed
     * instead; this scope is for cross-referencing from a game or player.
     */
    public function scopeMentioning(Builder $query, int $teamId): Builder
    {
        return $query->whereHas('teams', fn (Builder $q) => $q->whereKey($teamId));
    }
}
