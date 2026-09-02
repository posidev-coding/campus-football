<?php

namespace App\Models;

use App\Enums\LobbyFlavor;
use App\Support\ConferenceMarks;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
#[Fillable(['name', 'code', 'kind', 'flavor', 'week_id', 'member_cap', 'filled_at'])]
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

    /**
     * What a seat here costs in Tallboys, and zero for almost everything.
     *
     * Reads off data that already exists: the flavor names a shelf, and the
     * shelf owns the price. Only PUBLIC contests can charge — you never
     * spend inside a private league, which is what dissolves the
     * multi-group supply problem (a member of four leagues would otherwise
     * need four times the income), and a flavorless public room is a House
     * room, which is free.
     */
    public function entryCredits(): int
    {
        return $this->isLobby() ? ($this->flavorEnum()?->shelf()->entryCredits() ?? 0) : 0;
    }

    /**
     * The specialty this room is, if any. Stored as the raw backing value;
     * null is a STANDARD room, and an unrecognized value (a retired
     * flavor) degrades to standard rather than throwing on a room that
     * still has a URL.
     */
    public function flavorEnum(): ?LobbyFlavor
    {
        return $this->flavor === null ? null : LobbyFlavor::tryFrom($this->flavor);
    }

    /**
     * The commissioner's uploaded clubhouse icon, or null to fall back to
     * initials.
     *
     * Null is the normal case and every icon surface renders initials without
     * one — the fallback is the path most groups are on, not an error state.
     * The column holds a PATH; the disk decides what it resolves to.
     */
    public function iconUrl(): ?string
    {
        if (blank($this->icon)) {
            return null;
        }

        return Storage::disk(config('cfb.upload_disk'))->url($this->icon);
    }

    /**
     * The conference's mark for a conference-flavored room — the SEC shield
     * on the SEC Showdown — read off the logo ESPN synced onto
     * `conferences.logo`, through the one map ConferenceMarks holds.
     *
     * Null for every other group, and null for a conference ESPN shipped no
     * logo for: the caller keeps the mode tile, never an invented image.
     */
    public function conferenceLogoUrl(): ?string
    {
        return ConferenceMarks::logo($this->flavorEnum()?->conference());
    }

    /**
     * Initials for the icon fallback — the group's own name, up to two words.
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn (string $part) => Str::of($part)->substr(0, 1))
            ->implode('');
    }
}
