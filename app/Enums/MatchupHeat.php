<?php

namespace App\Enums;

use App\Models\GamePredictor;

/**
 * How worth watching an upcoming game looks, from fire down to snooze.
 *
 * ESPN's `matchup_quality` alone is not this. It scores how GOOD the two teams
 * are, not how close the game should be. Measured on 2026's opening weeks:
 * ECU at Alabama (a 27.5-point spread) rated 72.5, within two points of SMU at
 * Florida State (74.4, a field goal), and Ball State at Ohio State (49.5) rated
 * 52.6, level with UCLA at Cal (53.4, 2.5). A ring built on it alone would
 * light up the cupcake games.
 *
 * So the score is the GEOMETRIC mean of the two things a watchable game needs:
 * strength (`matchup_quality`) and closeness (how near ESPN's win projection
 * sits to 50/50, 0–100). A geometric mean needs BOTH — a coin flip between two
 * bad teams and a blowout between two good ones both land in the middle, and
 * only a close game between good teams reaches the top. Over the same weeks
 * that ordering put SMU–FSU, Louisville–Ole Miss and Clemson–LSU first and the
 * FCS visits to Virginia Tech and Missouri among the last.
 *
 * Closeness falls off on a CURVE, not a line: 1 − (edge / 50)², where edge is
 * the favorite's distance from 50%. A straight line was tried first and was
 * far too hard on an ordinary favorite — Texas at Tennessee, the
 * strongest matchup of Week 4 (98.9) on a 4.5-point line, lost 40% of its
 * closeness to ESPN's 70/30 projection and scored 77 beside two near-coin-flips
 * at 92. A 4.5-point game is a close game. The curve costs a 70/30 split 16%
 * and a 95/5 cupcake still 81%, so the top of a week reads as the week's big
 * games and the bottom stays the FCS visits.
 *
 * Both inputs ride the one predictor row, so a card needs one relation and no
 * ESPN request. Either input missing is NULL — an unmodelled game has no heat,
 * which is not the same as a cold one.
 *
 * Four tiers, not five: an odd-numbered scale grows a middle everything drifts
 * into. Fire sits at 80 so the flame stays rare — 9 of Week 4's 70 modelled
 * games, 4 of the opening weeks' 99. Snooze sits at 30 because the curve
 * lifts a big blowout into the twenties (Sam Houston at Texas Tech, 34.5
 * points, scores 28), and a 34.5-point game is a snooze: 17 of Week 4's 70,
 * and 54 of the opening weeks' 99, which were FCS visits all the way down.
 */
enum MatchupHeat: string
{
    case Fire = 'fire';
    case Warm = 'warm';
    case Mild = 'mild';
    case Snooze = 'snooze';

    /**
     * 0–100, or null when the game cannot be scored.
     */
    public static function score(?GamePredictor $predictor): ?int
    {
        if ($predictor?->matchup_quality === null || $predictor->home_projection === null) {
            return null;
        }

        $strength = min(100.0, max(0.0, $predictor->matchup_quality));
        $edge = min(50.0, abs($predictor->home_projection - 50.0));
        $closeness = 100.0 * (1.0 - ($edge / 50.0) ** 2);

        return (int) round(sqrt($strength * $closeness));
    }

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 80 => self::Fire,
            $score >= 45 => self::Warm,
            $score >= 30 => self::Mild,
            default => self::Snooze,
        };
    }

    /**
     * Plain words for a screen reader. The icon carries the attitude; the
     * card also sits on Scores, where the copy stays factual.
     */
    public function label(): string
    {
        return match ($this) {
            self::Fire => 'Must-watch matchup',
            self::Warm => 'Strong matchup',
            self::Mild => 'Average matchup',
            self::Snooze => 'Mismatch',
        };
    }
}
