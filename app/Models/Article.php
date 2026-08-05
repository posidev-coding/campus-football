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

    /**
     * ESPN's type for a video or photo post.
     *
     * These carry no story at all — 78 of our 212 articles, and every one of
     * the eight in a sampled 18 came back with an empty body. Asking for one is
     * a request we already know the answer to.
     */
    public const MEDIA = 'Media';

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'story_fetched_at' => 'datetime',
            'story_images' => 'array',
            'premium' => 'boolean',
        ];
    }

    /**
     * Whether asking ESPN for this article's body could tell us anything new.
     *
     * False once we have asked, whatever came back: a `Media` post has no story
     * to find, and an article that answered with nothing will answer with
     * nothing again. Without this, every view of every video post is a request.
     */
    public function storyIsWorthFetching(): bool
    {
        return $this->story === null
            && $this->story_fetched_at === null
            && $this->type !== self::MEDIA;
    }

    /**
     * Whether to send a reader to our own page rather than to espn.com.
     *
     * Optimistic before the first fetch — we cannot know a body exists without
     * asking, and asking on the LIST would be one request per card. So a card
     * links inward whenever a story is plausible, and the article page falls
     * back gracefully on the rare miss.
     */
    public function isReadable(): bool
    {
        return $this->story !== null || $this->storyIsWorthFetching();
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
