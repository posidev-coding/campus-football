<?php

namespace App\Services\Contests;

use App\Enums\SlateFilter;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateGame;

/**
 * The rules of one contest mode, as an object the rest of the phase asks
 * instead of hardcoding: how big a board is, whether and how it tiers, what
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
    public function __construct(protected ?array $settings = null) {}

    /** One knob from the contest's settings column, or the mode's default. */
    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * The themed admission rule this contest's boards draw under, or null
     * for the standard everything-eligible pool. Read by SuggestSlate; the
     * engine itself never filters — a filter shapes what reaches the board,
     * never how the board grades.
     */
    public function slateFilter(): ?SlateFilter
    {
        $filter = $this->setting('slate_filter');

        return $filter === null ? null : SlateFilter::tryFrom((string) $filter);
    }

    /** How many games a published board carries. */
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
        return $result === Pick::WIN
            ? $this->pointsFor($slateGame) + $this->kickerBonus($slateGame, $pick)
            : 0;
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
            // The contest line is what the whole board grades against; a
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
             * ONE BOARD, ONE SATURDAY. This used to compare week ids, which
             * a split ESPN week satisfies twice over: 2026's Week 1 holds
             * both 8/29 and 9/5, so a board could be built across two
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
     * A complete question: a game on this board, a metric, and — when the
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
        // Classic board is data that would grade differently the day the
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
