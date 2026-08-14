<?php

namespace App\Models;

use Database\Factories\PickFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's call on one slate game.
 *
 * A missed pick is an ABSENT ROW worth zero — nothing ever writes a pick on
 * a user's behalf, which is the no-defaults rule wearing a primary key.
 * `result` and `points` are null until the game is graded; after grading,
 * points of 0 means "earned nothing" and a NEGATIVE number is a Woodshed
 * Lock that backfired — null, zero and negative are different statements
 * and callers must not conflate them.
 *
 * Two different "locked"s live near this model, deliberately apart:
 * kickoff lock state is TEMPORAL (the game started), never a stored flag
 * that can drift — privacy until kickoff is a query rule for the same
 * reason, and your own picks are always yours to see. The `locked` COLUMN
 * is the other thing entirely: the Woodshed's Lock WAGER, a deliberate
 * player choice on the featured game (+6 right, −4 wrong) written through
 * LockPick and frozen by the same kickoff clock as the pick itself.
 */
#[Fillable(['slate_game_id', 'user_id', 'picked_team_id', 'locked', 'result', 'points'])]
class Pick extends Model
{
    /** @use HasFactory<PickFactory> */
    use HasFactory;

    public const WIN = 'win';

    public const LOSS = 'loss';

    public const PUSH = 'push';

    protected function casts(): array
    {
        return [
            'locked' => 'boolean',
            'points' => 'integer',
        ];
    }

    /**
     * Picks the given user is allowed to SEE: their own always, everyone
     * else's only once the game has kicked off. Privacy-until-lock is this
     * query rule and nothing else — never a stored flag that can drift.
     * The lock is temporal, so the guard is the same clock-or-feed check
     * Game::hasKickedOff() makes, expressed as SQL.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('user_id', $user->id)
            ->orWhereHas('slateGame.game', fn (Builder $game) => $game
                ->where(fn (Builder $g) => $g
                    ->where('completed', true)
                    ->orWhere('kickoff_at', '<=', now())
                    ->orWhereIn('status', ['in', 'halftime', 'end-period']))));
    }

    public function slateGame(): BelongsTo
    {
        return $this->belongsTo(SlateGame::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pickedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'picked_team_id');
    }
}
