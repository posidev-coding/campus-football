<?php

namespace App\Models;

use App\Enums\ContentRating;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * `admin` is deliberately absent from Fillable — it is a privilege escalation
 * vector the moment it reaches a mass-assignment path from a request.
 */
#[Fillable([
    'first_name', 'last_name', 'handle', 'email', 'password',
    'avatar', 'timezone', 'content_rating', 'favorite_team_id',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Mirrors the database defaults so a newly-created model is usable before
     * it is refreshed from the database. Without this, `$user->content_rating`
     * is null on the instance returned by `create()` even though the column has
     * a default, and any caller treating it as an enum fatals.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'admin' => false,
        'timezone' => 'America/New_York',
        'content_rating' => ContentRating::Pg13->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'password' => 'hashed',
            'admin' => 'boolean',
            'content_rating' => ContentRating::class,
        ];
    }

    /**
     * How many teams one user may follow.
     *
     * The favorite counts as one of them — it is a followed team that also
     * leads the home page, not a separate slot. Capped because followed teams
     * float above the scoreboard's day groups: past a handful the pinned block
     * stops being a shortcut and becomes the slate all over again, and every
     * follow also commits us to syncing that team's news feed.
     */
    public const MAX_FOLLOWED_TEAMS = 5;

    /**
     * The one team whose news leads this user's home page.
     */
    public function favoriteTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'favorite_team_id');
    }

    /**
     * Teams this user follows.
     *
     * A pivot rather than v3's JSON column, so the per-team news sync can ask
     * "which teams does anyone follow" as an indexed query rather than by
     * scanning every user row and decoding JSON.
     */
    public function followedTeams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_follows')->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->admin === true;
    }

    /**
     * Without this, Filament lets every authenticated user into the panel
     * outside of local. It is enforced in all environments here, including
     * local, so the behaviour under test matches the behaviour in production.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin();
    }

    public function hasOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }

    /**
     * Initials for the avatar fallback.
     */
    /**
     * Full name, assembled rather than stored.
     *
     * Registration collects the two halves separately, but plenty of places
     * just want to print a person — so `$user->name` still works and nothing
     * that used it had to change.
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn (string $part) => Str::of($part)->substr(0, 1))
            ->implode('');
    }
}
