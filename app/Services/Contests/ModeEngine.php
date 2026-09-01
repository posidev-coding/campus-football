<?php

namespace App\Services\Contests;

use App\Enums\SlateFilter;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateGame;

/**
 * The rules of one contest mode, as an object the rest of the phase asks
 * instead of hardcoding: how big a slate is, whether and how it tiers, what
 * a pick is worth, and whether a draft is fit to publish.
 *
 * An abstract base rather than an interface, deliberately:
 * validateForPublish() is FINAL, so the shared invariants — a line frozen
 * on every game, Saturday only, the right week, nothing started, a
 * tiebreaker designated — cannot be forgotten or half-reimplemented by a
 * mode. A mode contributes its own rules through the small hooks below.
 *
 * `$settings` is the contest's knob column: null means "this mode's
 * defaults", and engines read it lazily so The Woodshed's rules can arrive
 * as settings plus code with zero schema churn.
 */
abstract class ModeEngine
{
    /**
     * THE TALLBOY WAGER: symmetric, flat, and never scaled.
     *
     * Five rather than ten because most games are worth ten, so every
     * score sits on a ten-point lattice: a ten-point wager keeps you ON
     * that lattice and therefore breaks no ties at all, while a five lands
     * wagerers on the fives and everyone else on the zeros, where the two
     * can never tie. It does not hand the tie to whoever paid — half the
     * time the separation is downward.
     *
     * It stays FLAT even where it exceeds the game. Triple Option's tier-3
     * games pay four, so a wager there outweighs the game itself; that is
     * a choice, not an oversight, and it is what makes a junk game worth
     * wagering on. Scaling to the tier yields fractions on nines and
     * sevens.
     */
    public const TALLBOY_SWING = 5;

    /**
     * A wager may never be worth more than roughly this much of a perfect
     * week — the guard that outlives today's ten rooms.
     *
     * Measured as swing ÷ perfect week: Shotgun 5/100 = 5%, Under the
     * Lights 5/80 = 6.3%, Triple Option 5/100 = 5%. A THIN Saturday is
     * what this is really for — three games is a 30-point week and 16.7%,
     * refused.
     */
    public const TALLBOY_LEVERAGE_CEILING = 0.15;

    public function __construct(protected ?array $settings = null) {}

    /** One knob from the contest's settings column, or the mode's default. */
    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * The themed admission rule this contest's slates draw under, or null
     * for the standard everything-eligible pool. Read by SuggestSlate; the
     * engine itself never filters — a filter shapes what reaches the slate,
     * never how the slate grades.
     */
    public function slateFilter(): ?SlateFilter
    {
        $filter = $this->setting('slate_filter');

        return $filter === null ? null : SlateFilter::tryFrom((string) $filter);
    }

    /** How many games a published slate carries. */
    abstract public function slateSize(): int;

    /**
     * Tier layout as [tier => game count], or null for an untiered mode.
     * Null means "no tiers exist", not "one big tier" — Classic slate games
     * carry a null tier and nothing may default it.
     *
     * @return array<int, int>|null
     */
    abstract public function tierSpec(): ?array;

    /**
     * The RAW value of this slate game — what a plain won pick pays, the
     * number a tier heading prints, and the Bear's per-game worth. What a
     * PARTICULAR pick pays is pointsForPick()'s question.
     */
    abstract public function pointsFor(SlateGame $slateGame): int;

    /**
     * What THIS pick pays given its result — the one seam live grading
     * (PickGrader) and settlement share, so the money math can never fork.
     * Base rule: a win pays pointsFor() plus any settings-driven kicker,
     * anything else pays zero. The Woodshed overrides it to price the Lock
     * wager, where a locked loss pays NEGATIVE points.
     */
    public function pointsForPick(SlateGame $slateGame, Pick $pick, string $result): int
    {
        /*
         * THE TALLBOY, priced in the one seam live grading and settlement
         * share, so the money math can never fork. `picks.locked` is the
         * stored wager for BOTH mechanics — the Woodshed overrides this
         * method for the Lock, and the two are mutually exclusive by
         * design, so one column serves both. A locked pick under a mode
         * that offers neither is inert data and grades plainly.
         */
        if ($pick->locked && $this->supportsTallboy()) {
            return match ($result) {
                Pick::WIN => $this->pointsFor($slateGame) + self::TALLBOY_SWING,
                Pick::LOSS => -self::TALLBOY_SWING,
                // Defense only — the half-point law makes a push unreachable.
                Pick::PUSH => 0,
            };
        }

        return $result === Pick::WIN
            ? $this->pointsFor($slateGame) + $this->kickerBonus($slateGame, $pick)
            : 0;
    }

    /**
     * The upset kicker's bonus when this contest carries one, or null —
     * what the pick surface reads to say the house rule out loud.
     */
    public function kickerPoints(): ?int
    {
        return $this->setting('kicker') === 'underdog_ml'
            ? (int) $this->setting('kicker_points', 2)
            : null;
    }

    /**
     * The settings-driven bonus arm of a winning pick. `underdog_ml`: the
     * dog pick covered (that is the win this rides on) AND won the game
     * outright — judged only on a COMPLETED game, so live grading pays the
     * plain price mid-game and the final regrade adds the bump. The
     * recompute is idempotent, so the bump is just the next pass.
     *
     * Reads `$slateGame->game`, which PickGrader pins to the live row
     * before grading — the same score the result was computed from.
     */
    protected function kickerBonus(SlateGame $slateGame, Pick $pick): int
    {
        if ($this->setting('kicker') !== 'underdog_ml') {
            return 0;
        }

        $game = $slateGame->game;

        // Never read the spread's sign for this — favorite_team_id is the
        // only honest statement of who the dog is.
        if (! $game->completed || $pick->picked_team_id === $slateGame->favorite_team_id) {
            return 0;
        }

        $pickedHome = $pick->picked_team_id === $game->home_team_id;
        $picked = $pickedHome ? $game->home_score : $game->away_score;
        $other = $pickedHome ? $game->away_score : $game->home_score;

        return $picked > $other ? (int) $this->setting('kicker_points', 2) : 0;
    }

    /** Whether this mode offers the Lock wager on the featured game. */
    public function supportsLock(): bool
    {
        return false;
    }

    /**
     * Whether the SLATE SIZE this engine reads was frozen from a real
     * Saturday or is just the mode's default. A dynamic room's answer to
     * supportsTallboy() is only as good as the card it ends up dealing, so
     * an explainer with no contest in hand must say "when the card is big
     * enough" rather than a flat yes.
     */
    public function sizeIsFrozen(): bool
    {
        return $this->setting('slate_size') !== null;
    }

    /**
     * The Tallboy rule for THIS contest, in one plain sentence.
     *
     * Product vocabulary and register-constant, the ContestMode::ruleLines()
     * posture: the game is never described two ways, so the explainer,
     * the room grid and the docs all read this rather than restating it.
     * DERIVED from the same three exclusions supportsTallboy() applies, so
     * a room that changes shape cannot end up with a stale reason printed
     * beside a correct answer.
     */
    public function tallboyRule(): string
    {
        return match (true) {
            $this->supportsLock() => 'No Tallboy — the Lock is this mode\'s wager, and a slate never offers two.',
            $this->kickerPoints() !== null => 'No Tallboy — the underdog kicker is already riding on every winning pick.',
            $this->setting('tallboy', true) === false => 'No Tallboy — this room is in and out, with nothing to weigh.',
            ! $this->supportsTallboy() => 'No Tallboy — '.self::TALLBOY_SWING.' points is too big a swing for a card this short.',
            default => 'Crush a Tallboy on any one game: +'.self::TALLBOY_SWING.' right, −'.self::TALLBOY_SWING.' wrong.',
        };
    }

    /**
     * The most a perfect week pays before any wager — the denominator the
     * Tallboy's leverage is measured against, and derived rather than
     * re-typed. Untiered modes are size × the standard ten; a tiered mode
     * overrides with its own arithmetic.
     */
    public function perfectWeek(): int
    {
        return $this->slateSize() * ClassicMode::GAME_POINTS;
    }

    /**
     * Whether THIS CONTEST takes the Tallboy wager.
     *
     * EVALUATED PER SLATE, NOT PER FLAVOR, and that is the whole point of
     * asking the engine rather than a list. Ranked Action and all five
     * conference rooms are dynamic-size: their slate is as big as the
     * Saturday allows, frozen into `contests.settings.slate_size` at spawn.
     * A thin conference week can seat three games — a 30-point perfect
     * week, where ±5 is 16.7% and over the ceiling. A static per-flavor
     * allowlist ships a silent over-leverage bug on the first thin
     * Saturday; this reads the contest's own frozen size, exactly the way
     * blurb($games) takes the contest's size rather than the mode's.
     *
     * Three exclusions above the arithmetic:
     *
     *  - A mode that already owns a wager. The Woodshed has the Lock, and a
     *    slate must never offer two — which is also why one `picks.locked`
     *    column can serve both mechanics with no migration.
     *  - A mode carrying a KICKER. Upset Alley's underdog bonus already
     *    stacks onto a winning pick, and a second modifier on the same pick
     *    is unreadable.
     *  - `tallboy => false` in settings. Two-Minute Drill is excluded on
     *    IDENTITY, not arithmetic: at ±5 its leverage is 10% and inside the
     *    ceiling, but its own blurb sells it as "the flash card: in and
     *    out", and a wager is friction. Keeping one public shelf with zero
     *    spend decisions is also the clean answer to "is the Lobby
     *    pay-to-play?".
     */
    public function supportsTallboy(): bool
    {
        if ($this->supportsLock() || $this->kickerPoints() !== null) {
            return false;
        }

        if ($this->setting('tallboy', true) === false) {
            return false;
        }

        $perfect = $this->perfectWeek();

        return $perfect > 0
            && self::TALLBOY_SWING / $perfect <= self::TALLBOY_LEVERAGE_CEILING;
    }

    /** Whether the Bear rides this mode's slates. */
    public function hasBear(): bool
    {
        return false;
    }

    /**
     * Every reason this slate cannot be published, as Voice keys — [] means
     * publishable. Keys, not prose: the slate builder resolves them through
     * Voice so the copy carries all three registers (written with that
     * screen), and a key can be asserted in a test without pinning prose.
     *
     * @return list<string>
     */
    final public function validateForPublish(Slate $slate): array
    {
        $slate->loadMissing('games.game');

        $problems = [];
        $games = $slate->games;

        if ($games->count() !== $this->slateSize()) {
            $problems[] = 'picks.publish.count';
        }

        foreach ($games as $slateGame) {
            // The contest line is what the whole slate grades against; a
            // game without one cannot be slated in an ATS-only product.
            if ($slateGame->spread === null || $slateGame->favorite_team_id === null) {
                $problems[] = 'picks.publish.line_missing';
            }

            // THE HALF-POINT LAW: no contest line ever sits on a whole
            // number, so no pick can ever push. The founders' rule.
            if ($slateGame->spread !== null && ! ContestLine::isHalfPoint($slateGame->spread)) {
                $problems[] = 'picks.publish.whole_line';
            }

            // Contests run on a Saturday cadence, noon to midnight
            // Eastern — the window Game::inSlateWindow() holds.
            if (! $slateGame->game->inSlateWindow()) {
                $problems[] = 'picks.publish.not_saturday';
            }

            /*
             * ONE SLATE, ONE SATURDAY. This used to compare week ids, which
             * a split ESPN week satisfies twice over: 2026's Week 1 holds
             * both 8/29 and 9/5, so a slate could be built across two
             * Saturdays a week apart and every check still passed. The
             * slate's own Saturday is the honest comparison.
             */
            if ($slateGame->game->kickoff_at?->timezone(config('cfb.timezone'))->toDateString() !== $slate->saturday?->toDateString()) {
                $problems[] = 'picks.publish.wrong_saturday';
            }

            if ($this->hasStarted($slateGame)) {
                $problems[] = 'picks.publish.started';
            }
        }

        if (! $this->tiebreakerDesignated($slate)) {
            $problems[] = 'picks.publish.tiebreaker';
        }

        if (! $this->tiersSatisfySpec($slate)) {
            $problems[] = 'picks.publish.tiers';
        }

        return array_values(array_unique([...$problems, ...$this->validateModeRules($slate)]));
    }

    /**
     * Mode-specific publish rules beyond the shared invariants. The seam
     * The Woodshed's rules will occupy.
     *
     * @return list<string>
     */
    protected function validateModeRules(Slate $slate): array
    {
        return [];
    }

    /**
     * The same question the pick lock asks, through the same method, so
     * publish validation and the lock can never disagree on "started".
     */
    private function hasStarted(SlateGame $slateGame): bool
    {
        return $slateGame->game->hasKickedOff();
    }

    /**
     * A complete question: a game on this slate, a metric, and — when the
     * metric is about one side — a team that is actually in that game.
     */
    private function tiebreakerDesignated(Slate $slate): bool
    {
        $tiebreakerGame = $slate->games->firstWhere('id', $slate->tiebreaker_slate_game_id);

        if ($tiebreakerGame === null || $slate->tiebreaker_metric === null) {
            return false;
        }

        if (! $slate->tiebreaker_metric->needsTeam()) {
            return true;
        }

        return in_array($slate->tiebreaker_team_id, [
            $tiebreakerGame->game->home_team_id,
            $tiebreakerGame->game->away_team_id,
        ], true);
    }

    private function tiersSatisfySpec(Slate $slate): bool
    {
        $spec = $this->tierSpec();

        // Untiered mode: every tier must be null — a stray tier on a
        // Classic slate is data that would grade differently the day the
        // contest's mode was misread.
        if ($spec === null) {
            return $slate->games->every(fn (SlateGame $g) => $g->tier === null);
        }

        $counts = $slate->games->countBy('tier');

        if ($counts->has('')) {
            return false;
        }

        foreach ($spec as $tier => $expected) {
            if (($counts[$tier] ?? 0) !== $expected) {
                return false;
            }
        }

        return $counts->keys()->diff(array_keys($spec))->isEmpty();
    }
}
