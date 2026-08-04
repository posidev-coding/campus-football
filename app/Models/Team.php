<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Scout\Searchable;

/**
 * Note what is absent: there is no `conference_id` column and no `conference()`
 * relation. Conference membership is a fact about a team *in a season*, so it
 * lives on TeamSeason and must be asked for with a year.
 */
#[Fillable([
    'id', 'slug', 'location', 'name', 'nickname', 'abbreviation',
    'display_name', 'short_display_name', 'color', 'alt_color', 'logo', 'logo_dark',
])]
class Team extends Model
{
    use HasFactory, Searchable;

    /**
     * Above this, a place name is shortened rather than truncated.
     *
     * Sized to the narrowest place a team is named, which is NOT the phone —
     * a card is full width at 390px and roughly 300px when it goes two-up at
     * `sm`. That leaves about 144px for the name once the logo, rank, record
     * and score column are taken out, or about eighteen characters at
     * `text-sm`. Sixteen keeps a margin for the widest glyphs.
     *
     * Four FBS teams cross it: Florida International, Jacksonville State,
     * Mississippi State, Northern Illinois. The other 132 are untouched.
     */
    private const MAX_PLACE_NAME_LENGTH = 16;

    public $incrementing = false;

    protected $keyType = 'int';

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Scout's database engine searches these COLUMNS — the values here are
     * never stored anywhere. No strategy attributes, so every column matches
     * anywhere in the string: at 854 rows the scan is nothing, and "bulldogs"
     * finding Georgia mid-nickname is worth more than an index could save.
     *
     * Deliberately not MySQL FULLTEXT: an InnoDB full-text index cannot see
     * rows inserted inside an uncommitted transaction, which is every row a
     * RefreshDatabase test creates — the feature would pass in production and
     * be untestable, which is the wrong way round.
     *
     * @return array<string, string|null>
     */
    public function toSearchableArray(): array
    {
        return [
            'display_name' => $this->display_name,
            'location' => $this->location,
            'nickname' => $this->nickname,
            'abbreviation' => $this->abbreviation,
        ];
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(TeamSeason::class);
    }

    public function seasonFor(int $year): HasOne
    {
        return $this->hasOne(TeamSeason::class)->where('season_year', $year);
    }

    public function standings(): HasMany
    {
        return $this->hasMany(Standing::class);
    }

    public function homeGames(): HasMany
    {
        return $this->hasMany(Game::class, 'home_team_id');
    }

    public function awayGames(): HasMany
    {
        return $this->hasMany(Game::class, 'away_team_id');
    }

    public function rankings(): HasMany
    {
        return $this->hasMany(Ranking::class);
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_follows')->withTimestamps();
    }

    /**
     * Every game this team played, home or away.
     *
     * Home and away are separate denormalized columns on `games` — that is what
     * keeps the scoreboard join-free — so a team's schedule is a union rather
     * than a single relation, and the two scopes above cannot express it.
     */
    public function games(): Builder
    {
        return Game::query()->where(fn ($q) => $q
            ->where('home_team_id', $this->id)
            ->orWhere('away_team_id', $this->id));
    }

    /**
     * Hex colors arrive from ESPN without a leading `#`.
     */
    public function accentColor(): ?string
    {
        return $this->color ? '#'.ltrim($this->color, '#') : null;
    }

    /**
     * The secondary color, same caveat. Often white or a metallic; used as a
     * keyline against the primary, never as a surface of its own.
     */
    public function altAccentColor(): ?string
    {
        return $this->alt_color ? '#'.ltrim($this->alt_color, '#') : null;
    }

    /**
     * Black or white — whichever actually survives on top of the accent.
     *
     * This used to be hardcoded white, which fails the same way the logo did:
     * white text on Tennessee orange is about 2.4:1, and on a maize or gold
     * accent it disappears entirely. YIQ luminance with a threshold of 150
     * sends every light accent to near-black text; Tennessee's #FF8200 sits at
     * 152 and lands — correctly — on the dark side.
     */
    public function accentContrast(): ?string
    {
        $hex = ltrim((string) $this->color, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }

        $yiq = (hexdec(substr($hex, 0, 2)) * 299
            + hexdec(substr($hex, 2, 2)) * 587
            + hexdec(substr($hex, 4, 2)) * 114) / 1000;

        return $yiq >= 150 ? '#18181b' : '#ffffff';
    }

    /**
     * The team's place without its nickname — "North Carolina", not "North
     * Carolina Tar Heels".
     *
     * This is what a scoreboard says out loud. The nickname is decoration on a
     * card whose real job is the matchup, and it is decoration that costs the
     * most where there is least room: "Tar Heels" is nine characters that push
     * the part a reader is scanning for out of view.
     *
     * Past MAX_LENGTH it falls back to `short_display_name`, ESPN's own
     * shortening — "Florida International" becomes FIU, "Mississippi State"
     * becomes Mississippi St. That is only 33 of 136 FBS teams; for the other
     * 103 the two fields are already identical, so the substitution is
     * invisible everywhere it is not needed.
     *
     * Note this is NOT breakpoint-conditional, and deliberately so. A game card
     * is roughly the same width at every breakpoint — one column at 390px is
     * about 334px of usable space, while two-up at `sm` is about 276px — so
     * gating on the viewport would swap in the SHORT name exactly where there
     * is most room and the long one where there is least.
     */
    public function placeName(): string
    {
        $location = $this->location ?: $this->display_name;

        if (mb_strlen($location) <= self::MAX_PLACE_NAME_LENGTH) {
            return $location;
        }

        return $this->short_display_name ?: $location;
    }
}
