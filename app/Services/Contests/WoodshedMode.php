<?php

namespace App\Services\Contests;

use App\Enums\TiebreakerMetric;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateGame;

/**
 * The Woodshed: the founders' game, recovered from the 2016 league and
 * implemented whole.
 *
 * Fifteen games in three tiers of five paying 8/6/4 — a 90-point slate.
 * Two mechanics are all its own. The LOCK: an optional +6/−4 wager on the
 * featured game (the designated tiebreaker game — one designation serves
 * both), the only path to negative points. The BEAR: the house's mythical
 * contestant, whose themed picks are stamped at publish (BearPicks) and
 * whose weekly RAW total must be STRICTLY beaten by your adjusted total
 * for +5 at settlement. A perfect week is 90 + 6 + 5 = 101 — one point of
 * founders' premium over the other modes' 100.
 *
 * The founders' values are constants; `$settings` remains the tuning
 * landing pad for the day a league wants its own numbers (not built).
 */
class WoodshedMode extends ModeEngine
{
    /** @var array<int, int> */
    public const TIER_POINTS = [1 => 8, 2 => 6, 3 => 4];

    public const LOCK_BONUS = 6;

    public const LOCK_PENALTY = 4;

    public const BEAR_BONUS = 5;

    /**
     * `slate_size` is deliberately NOT honored here — the founders' game is
     * fifteen in three tiers of five, and a short slate has no honest
     * 5-5-5. Only the untiered mode flexes (the TieredMode posture).
     */
    public function slateSize(): int
    {
        return 15;
    }

    public function tierSpec(): ?array
    {
        return [1 => 5, 2 => 5, 3 => 5];
    }

    /**
     * Match is deliberately non-exhaustive (the TieredMode posture): a
     * Woodshed slate game outside tiers 1-3 cannot survive publish
     * validation, so an UnhandledMatchError here is corrupt data
     * announcing itself, not a case to paper over.
     */
    public function pointsFor(SlateGame $slateGame): int
    {
        return match ($slateGame->tier) {
            1 => self::TIER_POINTS[1],
            2 => self::TIER_POINTS[2],
            3 => self::TIER_POINTS[3],
        };
    }

    /**
     * The Lock priced: an unlocked pick grades like anyone else's; a
     * locked win pays the tier plus the bonus, a locked loss pays MINUS
     * the penalty. The push arm is defense only — the half-point law makes
     * a push unreachable.
     */
    public function pointsForPick(SlateGame $slateGame, Pick $pick, string $result): int
    {
        if (! $pick->locked) {
            return parent::pointsForPick($slateGame, $pick, $result);
        }

        return match ($result) {
            Pick::WIN => $this->pointsFor($slateGame) + self::LOCK_BONUS,
            Pick::LOSS => -self::LOCK_PENALTY,
            Pick::PUSH => 0,
        };
    }

    public function supportsLock(): bool
    {
        return true;
    }

    /** 8·5 + 6·5 + 4·5 = 90, before the Lock and the Bear. */
    public function perfectWeek(): int
    {
        $total = 0;

        foreach ($this->tierSpec() as $tier => $count) {
            $total += $count * self::TIER_POINTS[$tier];
        }

        return $total;
    }

    public function hasBear(): bool
    {
        return true;
    }

    /**
     * The featured game IS the tiebreaker game, and the Woodshed's
     * question is always its over/under — the OG rule kept as a publish
     * invariant. A null metric is the shared tiebreaker check's problem;
     * this rule only refuses a WRONG one.
     */
    protected function validateModeRules(Slate $slate): array
    {
        if ($slate->tiebreaker_metric === null || $slate->tiebreaker_metric === TiebreakerMetric::CombinedPoints) {
            return [];
        }

        return ['picks.publish.featured_metric'];
    }
}
