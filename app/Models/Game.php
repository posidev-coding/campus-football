<?php

namespace App\Models;

use App\Support\Cadence;
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

    /**
     * Every ESPN state that means "the ball is in play right now". Halftime and
     * end-of-period are live: the clock is stopped, the game is not.
     *
     * @var list<string>
     */
    public const LIVE_STATUSES = ['in', 'halftime', 'end-period'];

    /**
     * How long after kickoff a game is still PRESUMED live when the feed has
     * not caught up. Long enough for a weather delay, short enough that a
     * postponed game which never leaves `pre` stops holding the live tier
     * open. See {@see scopeExpectedLive()}.
     */
    private const KICKOFF_GRACE_HOURS = 6;

    public $incrementing = false;

    protected $keyType = 'int';

    protected static function booted(): void
    {
        // Cadence hydrates a week's slate-window games ONCE and holds them in
        // a static. A queue worker runs many jobs in one process, so the sync
        // that adds Saturday's games has to drop that memo or the next job
        // answers off the rows it held before them.
        static::saved(fn () => Cadence::forgetWeeks());
        static::deleted(fn () => Cadence::forgetWeeks());
    }

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
            'kickoff_alert_sent_at' => 'datetime',
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

    /**
     * The slate WINDOW: Saturday from noon Eastern, before Sunday.
     *
     * `slateEligible()` narrows a QUERY to Saturdays; this is the per-game
     * half of the same law, because the time-of-day boundary cannot be
     * asked in SQL without the timezone conversion `kickoff_day` exists to
     * avoid. A Saturday game at noon ET or later is necessarily before
     * Sunday ET, so one boundary check carries both ends of the window —
     * the morning kickoffs it excludes are real (Dublin games kick before
     * breakfast).
     *
     * BOTH HALVES READ `kickoff_at`. This asked `kickoff_day` for the
     * weekday and the timestamp for the hour, which is two sources for one
     * question — and when a kickoff MOVES without the denormalized column
     * following it, a Friday night game answers yes: it is publishable
     * onto a Saturday slate, and Cadence::saturdaysIn() reports its Friday
     * date as one of the week's Saturdays. `kickoff_day` stays the SQL
     * pre-filter it was added to be, and never the truth; the sync writes
     * it from this same converted instant.
     */
    public function inSlateWindow(): bool
    {
        if ($this->kickoff_at === null) {
            return false;
        }

        $kickoff = $this->kickoff_at->timezone(config('cfb.timezone'));

        return $kickoff->isSaturday() && $kickoff->hour >= 12;
    }

    /**
     * The pick'em LOCK question: has this game begun, by clock OR by feed?
     *
     * Both checks on purpose — a game that kicks early is live while its
     * scheduled time is still in the future, and trusting the clock alone
     * would leave picks open on a game already being played. Shared by the
     * pick lock and publish validation so the two can never disagree.
     */
    /**
     * The kickoff, in ET, in one of the app's three named styles — the
     * consolidation of five drifted hand-rolled formats ("7:30 PM" beside
     * "7:30pm" on sibling screens). Display stays ET for the pilot
     * audience; the user-timezone control is a deliberate post-launch
     * deferral. Null kickoff returns null — callers say 'TBD' themselves,
     * never a substituted time.
     *
     *   time   "7:30pm"          — beside a date something else prints
     *   day    "Sat 7:30pm"      — cards inside a known week
     *   date   "Sat, Sep 5 · 7:30pm" — standalone mentions
     */
    public function kickoffLabel(string $style = 'day'): ?string
    {
        if ($this->kickoff_at === null) {
            return null;
        }

        $local = $this->kickoff_at->setTimezone(config('cfb.timezone'));

        return match ($style) {
            'time' => $local->format('g:ia'),
            'day' => $local->format('D g:ia'),
            'date' => $local->format('D, M j · g:ia'),
        };
    }

    public function hasKickedOff(): bool
    {
        // NOT isPast(): that is a strict less-than, which would leave picks
        // open for the one second the clock reads exactly kickoff. The
        // kickoff moment itself is locked. A null kickoff (unscheduled) has
        // no clock to lock by — only the feed can say it started.
        return $this->completed
            || ($this->kickoff_at !== null && ! $this->kickoff_at->isFuture())
            || in_array($this->status, self::LIVE_STATUSES, true);
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
            ->whereIn('status', self::LIVE_STATUSES);
    }

    /**
     * Games the CLOCK says should be live, whatever the feed last told us.
     *
     * The live tier used to guard on {@see scopeInProgress()} alone, which can
     * only ever CONTINUE live coverage: a game sits at `pre` until a request
     * says otherwise, and the minute tier refused to spend one until something
     * was already `in`. The only scheduled tasks that could break the deadlock
     * were the hourly `--tier=current` and the 04:00 `--tier=recent`, so a noon
     * kickoff had no score, no clock and no gamecast until 13:00. Measured on
     * 2026-08-29: UNC at TCU was 10-10 in the second quarter on ESPN while
     * `cfb:games --tier=live` reported "0 changed, 0 requests".
     *
     * Bounded on BOTH ends, and the floor is why. "Any unfinished past
     * kickoff" would mean a postponed game that never leaves `pre` holds the
     * live tier open every minute of every window for the rest of the season.
     * A game genuinely being played goes `in` on the first pass this opens,
     * and `inProgress()` carries it from there however long it runs.
     *
     * A null kickoff is not a zero — an unscheduled fixture has no clock to be
     * late against, so it is simply not here.
     */
    public function scopeExpectedLive(Builder $query): Builder
    {
        return $query->where('completed', false)
            ->whereBetween('kickoff_at', [now()->subHours(self::KICKOFF_GRACE_HOURS), now()]);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('completed', false)->where('kickoff_at', '>=', now());
    }

    /**
     * Games kicking off inside the next $minutes — the kickoff-alert sweep's
     * window. Bounded on BOTH ends (upcoming() is not), and it rides the
     * (completed, kickoff_at) index.
     */
    public function scopeStartingSoon(Builder $query, int $minutes = 15): Builder
    {
        return $query->where('completed', false)
            ->whereBetween('kickoff_at', [now(), now()->addMinutes($minutes)]);
    }

    public function isInProgress(): bool
    {
        return ! $this->completed
            && in_array($this->status, self::LIVE_STATUSES, true);
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
