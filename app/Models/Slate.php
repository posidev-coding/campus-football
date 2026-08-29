<?php

namespace App\Models;

use App\Enums\TiebreakerMetric;
use Carbon\CarbonInterface;
use Database\Factories\SlateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One weekly slate: draft → published → settled, and never backwards.
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
            // The SATURDAY being played — the slate's real identity, and
            // what the whole weekly clock resolves from. `week_id` is still
            // ESPN's week and still drives labels; it is just not the key,
            // because one ESPN week can hold two Saturdays.
            'saturday' => 'immutable_date',
            'exhibition' => 'boolean',
            'published_at' => 'datetime',
            'settled_at' => 'datetime',
            'picks_reminded_at' => 'datetime',
            'last_call_sent_at' => 'datetime',
            'results_announced_at' => 'datetime',
            'tiebreaker_metric' => TiebreakerMetric::class,
        ];
    }

    /**
     * The first kickoff on this slate — the moment picks START locking.
     *
     * NOT the commissioner's deadline. `Cadence::slateDeadline()` is when an
     * unpublished slate forfeits to the standard card; players lock GAME BY
     * GAME at kickoff (`Game::hasKickedOff()`), so the first kickoff is the
     * only clock a player reminder can honestly be anchored on.
     *
     * Derived rather than stored, and read off the loaded relation: every
     * caller already has `games.game` in hand, and a column would be one more
     * thing to keep true when a game is rescheduled. Null when the slate has
     * no games or none of them has a kickoff yet — callers SKIP, they never
     * substitute a time.
     */
    public function firstKickoff(): ?CarbonInterface
    {
        return $this->games
            ->map(fn (SlateGame $slateGame) => $slateGame->game?->kickoff_at)
            ->filter()
            ->min();
    }

    /**
     * The next kickoff that has NOT happened yet — when the picks a reader
     * still owes begin to lock.
     *
     * Distinct from firstKickoff() and the reminder's real anchor. Once the
     * noon games have started, the first kickoff is in the past and stops
     * being a deadline for anybody; the 4pm card is still open and still
     * worth a last call. Anchoring on firstKickoff() would drop the whole
     * slate out of the window the moment its earliest game began, taking
     * every still-makeable pick with it.
     *
     * Null once every game has kicked: there is nothing left to be late for.
     */
    public function nextKickoff(): ?CarbonInterface
    {
        return $this->games
            ->reject(fn (SlateGame $slateGame) => $slateGame->game?->hasKickedOff() ?? true)
            ->map(fn (SlateGame $slateGame) => $slateGame->game?->kickoff_at)
            ->filter()
            ->min();
    }

    /**
     * A practice slate: graded, crowned and paid in XP, but never on the
     * season ledger — the clubhouse's season table, its "no history yet"
     * gate, and the group card's wins badge all leave it off. Those three
     * are joins that cannot call this method, so they ask the column by
     * the same name; change one and change them.
     *
     * Written once, at publish, from the configured practice window
     * (Cadence::isPractice) — moving the window afterwards must not
     * rewrite what a week people already played was worth.
     */
    public function counts(): bool
    {
        return ! $this->exhibition;
    }

    /** The guest commissioner who set this slate, if one was drawn. */
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
