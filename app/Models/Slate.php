<?php

namespace App\Models;

use App\Enums\TiebreakerMetric;
use Database\Factories\SlateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One weekly board: draft → published → settled, and never backwards.
 *
 * Publishing is what freezes each slate game's line (see PublishSlate);
 * `settled_at` doubles as the settlement claim — SettleSlate's atomic
 * whereNull-then-update on it is why a double-fired settlement pays nobody
 * twice.
 *
 * `bear_theme` names the Bear's weekly shtick on Woodshed slates, stamped
 * at publish by BearPicks. Null means "no Bear on this slate" — every
 * non-Woodshed slate, and never a default.
 */
#[Fillable([
    'contest_id', 'week_id', 'saturday', 'status', 'exhibition',
    'celebrity_user_id', 'published_at', 'settled_at',
    'tiebreaker_slate_game_id', 'tiebreaker_metric', 'tiebreaker_team_id',
    'bear_theme',
])]
class Slate extends Model
{
    /** @use HasFactory<SlateFactory> */
    use HasFactory;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    /**
     * Every game is final but the week is not yet OFFICIAL — the
     * stat-settling window between the last whistle and Cadence's
     * official-final moment, where ESPN's late corrections can still move
     * a tiebreaker. No payouts in this state.
     */
    public const PRELIM = 'prelim';

    public const SETTLED = 'settled';

    protected function casts(): array
    {
        return [
            // The SATURDAY being played — the board's real identity, and
            // what the whole weekly clock resolves from. `week_id` is still
            // ESPN's week and still drives labels; it is just not the key,
            // because one ESPN week can hold two Saturdays.
            'saturday' => 'immutable_date',
            'exhibition' => 'boolean',
            'published_at' => 'datetime',
            'settled_at' => 'datetime',
            'tiebreaker_metric' => TiebreakerMetric::class,
        ];
    }

    /** A practice board: graded and paid, but never counted. */
    public function counts(): bool
    {
        return ! $this->exhibition;
    }

    /** The guest commissioner who set this board, if one was drawn. */
    public function celebrity(): BelongsTo
    {
        return $this->belongsTo(User::class, 'celebrity_user_id');
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    public function games(): HasMany
    {
        return $this->hasMany(SlateGame::class)->orderBy('position');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SlateEntry::class);
    }

    public function tiebreakerGame(): BelongsTo
    {
        return $this->belongsTo(SlateGame::class, 'tiebreaker_slate_game_id');
    }

    public function tiebreakerTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'tiebreaker_team_id');
    }

    public function isPublished(): bool
    {
        return $this->status !== self::DRAFT;
    }
}
