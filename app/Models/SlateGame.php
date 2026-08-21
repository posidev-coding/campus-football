<?php

namespace App\Models;

use Database\Factories\SlateGameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One game on one slate, carrying the CONTEST LINE it will be graded
 * against.
 *
 * `spread` is the commissioner's number, not the book's: seeded from
 * game_odds' `current` phase when the game lands on the slate (nudged to a
 * half point — the league's no-push law), adjustable within
 * ContestLine::MAX_ADJUSTMENT of the market while the slate is a draft,
 * and immutable once published. `market_spread` keeps the book's own
 * number it was set against; provider and captured-at say whose book and
 * when. Null spread means the book had nothing when the game was added —
 * an honestly empty row publish refuses.
 *
 * Grading reads `spread` and only `spread`, whatever the market did after.
 *
 * `tier` is null when the mode has no tiers (Shotgun) — never a default 1.
 * There is no points column: a pick's value is f(mode, tier), the mode
 * engine's to compute.
 *
 * `bear_team_id` is the Bear's side of this matchup on Woodshed slates,
 * stamped at publish by BearPicks and public by design — the Bear is the
 * house's creature, not a Pick row, so privacy-until-kickoff never applied
 * to him. Null means "no Bear here" (every non-Woodshed slate).
 */
#[Fillable([
    'slate_id', 'game_id', 'tier', 'position', 'spread', 'market_spread',
    'favorite_team_id', 'bear_team_id', 'odds_provider', 'odds_captured_at',
])]
class SlateGame extends Model
{
    /** @use HasFactory<SlateGameFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'spread' => 'float',
            'market_spread' => 'float',
            'odds_captured_at' => 'datetime',
        ];
    }

    public function slate(): BelongsTo
    {
        return $this->belongsTo(Slate::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function favorite(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'favorite_team_id');
    }

    public function bearTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'bear_team_id');
    }

    public function picks(): HasMany
    {
        return $this->hasMany(Pick::class);
    }
}
