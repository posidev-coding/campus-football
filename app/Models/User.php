<?php

namespace App\Models;

use App\Enums\ContentRating;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * `admin` is deliberately absent from Fillable — it is a privilege escalation
 * vector the moment it reaches a mass-assignment path from a request.
 */
#[Fillable([
    'first_name', 'last_name', 'handle', 'email', 'password',
    'avatar', 'timezone', 'content_rating', 'newsletter_opt_in',
    'phone', 'sms_opt_in',
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
        'newsletter_opt_in' => true,
        /* False, unlike the newsletter. Signing up for a football app can
           fairly be read as wanting email about football; it cannot be read as
           consent to be texted. */
        'sms_opt_in' => false,
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
            'newsletter_opt_in' => 'boolean',
            'unsubscribed_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'sms_opt_in' => 'boolean',
            'sms_opted_in_at' => 'datetime',
        ];
    }

    /**
     * How many teams one user may follow.
     *
     * Capped because followed teams float above the scoreboard's day groups:
     * past a handful the pinned block stops being a shortcut and becomes the
     * slate all over again, and every follow also commits us to syncing that
     * team's news feed.
     */
    public const MAX_FOLLOWED_TEAMS = 5;

    /**
     * Teams this user follows, in the order THEY chose.
     *
     * A pivot rather than v3's JSON column, so the per-team news sync can ask
     * "which teams does anyone follow" as an indexed query rather than by
     * scanning every user row and decoding JSON.
     *
     * The order is the whole model now — it drives the Home swipe order, the
     * scoreboard float order, and whose news leads. There is deliberately no
     * `favoriteTeam()` anymore: singling out one team meant every surface had
     * to reconcile it with this list, including a union to cover the case
     * where the favorite was somehow not followed. Position 1 is the favorite.
     */
    public function followedTeams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_follows')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * Both auth emails go through our own notifications, which are branded and
     * — the part that matters — `ShouldQueue`.
     *
     * The framework's ResetPassword and VerifyEmail are not queued, so they
     * send INSIDE the web request. Against the `log` mailer that is invisible;
     * behind real SMTP it is a network round trip the user sits through after
     * pressing a button. Overriding here rather than calling `toMailUsing()`
     * is deliberate: that would have restyled the stock notifications without
     * moving them off the request, which is half the problem.
     *
     * Neither override changes the URL either notification produces, so the
     * receiving end — password.reset, and EmailVerificationRequest — is
     * untouched and does not know this happened.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Whether we are allowed to text this person.
     *
     * Three conditions, and all three are load-bearing. Consent is the legal
     * one. A VERIFIED number is the one that protects a stranger: a single
     * mistyped digit is somebody else's phone, and unlike a bounced email they
     * experience it as spam from a company they have never heard of.
     */
    public function canReceiveSms(): bool
    {
        return $this->sms_opt_in
            && filled($this->phone)
            && $this->phone_verified_at !== null;
    }

    /**
     * Where Vonage should send, or null to skip this notifiable entirely.
     *
     * The consent check lives HERE rather than in each notification's `via()`,
     * so it cannot be forgotten by a new one. Laravel skips a channel whose
     * route is falsy, so a user who has not opted in is not an error to handle
     * — they simply are not on this channel, and the same notification still
     * reaches them by mail.
     */
    public function routeNotificationForVonage(): ?string
    {
        return $this->canReceiveSms() ? $this->phone : null;
    }

    /**
     * The uploaded photo, or null to fall back to initials.
     *
     * Null is the normal case and every avatar surface already renders initials
     * without one — this column existed, fillable and unused, from the first
     * commit until avatars shipped, so the fallback path is the proven one.
     */
    public function avatarUrl(): ?string
    {
        if (blank($this->avatar)) {
            return null;
        }

        return Storage::disk(config('cfb.upload_disk'))->url($this->avatar);
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
