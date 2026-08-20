<?php

namespace App\Services\Contests;

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
     * Base rule: a win pays pointsFor(), anything else pays zero. The
     * Woodshed overrides it to price the Lock wager, where a locked loss
     * pays NEGATIVE points.
     */
    public function pointsForPick(SlateGame $slateGame, Pick $pick, string $result): int
    {
        return $result === Pick::WIN ? $this->pointsFor($slateGame) : 0;
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
