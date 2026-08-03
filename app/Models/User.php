<?php

namespace App\Models;

use App\Enums\TrashTalkIntensity;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * `admin` is deliberately absent from Fillable — it is a privilege escalation
 * vector the moment it reaches a mass-assignment path from a request.
 */
#[Fillable(['name', 'email', 'password', 'avatar', 'timezone', 'trash_talk_intensity'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Mirrors the database defaults so a newly-created model is usable before
     * it is refreshed from the database. Without this, `$user->trash_talk_intensity`
     * is null on the instance returned by `create()` even though the column has
     * a default, and any caller treating it as an enum fatals.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'admin' => false,
        'timezone' => 'America/New_York',
        'trash_talk_intensity' => TrashTalkIntensity::LockerRoom->value,
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
            'trash_talk_intensity' => TrashTalkIntensity::class,
        ];
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
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn (string $part) => Str::of($part)->substr(0, 1))
            ->implode('');
    }
}
