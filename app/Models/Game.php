<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Number;
use Laravel\Scout\Searchable;

/**
 * Note the absence of a `$with` property. v3 eager-loaded five relations on
 * every Game by default, which meant a contest listing fanned out badly before
 * a single line of query code was written. Callers ask for what they need.
 */
#[Fillable([
    'id', 'season_id', 'week_id', 'venue_id', 'kickoff_at', 'kickoff_day',
    'name', 'short_name', 'note', 'neutral_site', 'conference_game',
    'home_team_id', 'home_score', 'home_rank', 'home_record', 'home_line_scores', 'home_win_prob',
    'away_team_id', 'away_score', 'away_rank', 'away_record', 'away_line_scores', 'away_win_prob',
    'status', 'status_detail', 'period', 'clock', 'completed', 'attendance', 'broadcasts',
    'possession_team_id', 'down', 'distance', 'yard_line', 'down_distance_text',
    'is_red_zone', 'last_play_text', 'home_timeouts', 'away_timeouts',
])]
class Game extends Model
{
    use HasFactory, Searchable;

    /** Four quarters. ESPN keeps counting, so period 5 is the first overtime. */
    private const REGULATION_PERIODS = 4;

    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * Contains-LIKE, and that matters here: `name` is "Alabama at Georgia",
     * so a prefix strategy could never find a game by its AWAY team, and
     * `note` is the real bowl name — "Rose Bowl Presented by Prudential" —
     * where the word someone types is rarely the first one.
     *
     * @return array<string, string|null>
     */
    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'short_name' => $this->short_name,
            'note' => $this->note,
        ];
    }

    protected function casts(): array
    {
        return [
            'kickoff_at' => 'datetime',
            'neutral_site' => 'boolean',
            'conference_game' => 'boolean',
            'completed' => 'boolean',
            'home_line_scores' => 'array',
            'away_line_scores' => 'array',
            'broadcasts' => 'array',
            'home_win_prob' => 'float',
            'away_win_prob' => 'float',
            'is_red_zone' => 'boolean',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function odds(): HasMany
    {
        return $this->hasMany(GameOdd::class);
    }

    public function predictor(): HasOne
    {
        return $this->hasOne(GamePredictor::class);
    }

    public function summary(): HasOne
    {
        return $this->hasOne(GameSummary::class);
    }

    public function teamStats(): HasMany
    {
        return $this->hasMany(GameTeamStat::class);
    }

    public function scoringPlays(): HasMany
    {
        return $this->hasMany(GameScoringPlay::class);
    }

    public function athleteStats(): HasMany
    {
        return $this->hasMany(AthleteGameStat::class);
    }

    /**
     * Articles attached to this game by the summary sync — the recap
     * (`pivot.role = 'recap'`) plus ESPN's related list (`'related'`).
     */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Games a contest is allowed to slate.
     *
     * Contests run on a weekly Saturday cadence, so only Saturday kickoffs are
     * eligible. This reads the stored ET day rather than deriving it in SQL —
     * see the migration for why that distinction matters.
     */
    public function scopeSlateEligible(Builder $query): Builder
    {
        return $query->where('kickoff_day', 'Sat');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('completed', true);
    }

    /**
     * Playoff games, identified by ESPN's own event note.
     *
     * `games.name` is only ever "A at B", so before the note was stored there
     * was no way to tell the National Championship from a Tuesday MAC game —
     * a heuristic on `name` matched nothing at all.
     */
    public function scopePlayoff(Builder $query): Builder
    {
        return $query->where('note', 'like', 'College Football Playoff%');
    }

    /** Postseason games that are NOT part of the playoff bracket. */
    public function scopeBowlsOnly(Builder $query): Builder
    {
        return $query->whereNotNull('note')->where('note', 'not like', 'College Football Playoff%');
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('completed', false)->whereNotNull('status')
            ->whereIn('status', ['in', 'halftime', 'end-period']);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('completed', false)->where('kickoff_at', '>=', now());
    }

    public function isInProgress(): bool
    {
        return ! $this->completed
            && in_array($this->status, ['in', 'halftime', 'end-period'], true);
    }

    /**
     * "3rd", "OT", "2OT" — the period as a football screen names it.
     *
     * Regulation is four quarters and ESPN keeps counting past them, so period
     * 5 is the first overtime and every one after is numbered from there. A
     * bare ordinal would print "5th quarter", which no scoreboard says.
     */
    public function periodLabel(): ?string
    {
        if (! $this->period) {
            return null;
        }

        if ($this->period <= self::REGULATION_PERIODS) {
            return Number::ordinal($this->period);
        }

        $overtime = $this->period - self::REGULATION_PERIODS;

        return $overtime > 1 ? $overtime.'OT' : 'OT';
    }

    /**
     * "3rd · 2:11", falling back to ESPN's own detail.
     *
     * The clock is absent at a period break and between the whistle and the
     * next snap, and ESPN's `status_detail` covers those with "End of 3rd" or
     * "Halftime" — which is the more useful thing to read at that moment.
     */
    public function liveStatusLine(): ?string
    {
        $period = $this->periodLabel();

        return $period && $this->clock
            ? $period.' · '.$this->clock
            : $this->status_detail;
    }

    public function winnerTeamId(): ?int
    {
        if (! $this->completed || $this->home_score === $this->away_score) {
            return null;
        }

        return $this->home_score > $this->away_score
            ? $this->home_team_id
            : $this->away_team_id;
    }

    public function isTie(): bool
    {
        return $this->completed && $this->home_score === $this->away_score;
    }

    public function opponentOf(int $teamId): ?int
    {
        return match ($teamId) {
            $this->home_team_id => $this->away_team_id,
            $this->away_team_id => $this->home_team_id,
            default => null,
        };
    }
}
