<?php

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A pick'em group: the people one leaderboard is shouted across.
 *
 * There is no owner_id — the commissioner is a ROLE on group_members, so a
 * handoff is one UPDATE and the group never holds a second copy of who runs
 * it.
 *
 * Public contests are the same table wearing kind = 'lobby' with `week_id`
 * set: TRANSIENT WEEKLY ROOMS, capped at `member_cap` seats, spawned by
 * the house with no commissioner seat at all. `filled_at` is the spawn
 * claim — whereNull-then-update, so two racing joiners spawn exactly one
 * next room. A room persists at its URL forever; it just leaves the lobby
 * inventory when its week ends.
 */
#[Fillable(['name', 'code', 'kind', 'week_id', 'member_cap', 'filled_at'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    public const KIND_PRIVATE = 'private';

    public const KIND_LOBBY = 'lobby';

    /** Seats in a public room when the admin has not said otherwise. */
    public const DEFAULT_LOBBY_CAP = 20;

    protected function casts(): array
    {
        return [
            'member_cap' => 'integer',
            'filled_at' => 'datetime',
        ];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function contests(): HasMany
    {
        return $this->hasMany(Contest::class);
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    public function isLobby(): bool
    {
        return $this->kind === self::KIND_LOBBY;
    }

    /** A transient weekly room, as opposed to a season-long group. */
    public function isRoom(): bool
    {
        return $this->isLobby() && $this->week_id !== null;
    }
}
