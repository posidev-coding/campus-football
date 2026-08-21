<?php

namespace App\Enums;

use App\Models\Game;
use App\Models\GameTeamStat;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\Team;

/**
 * What a week's tiebreaker question is ABOUT.
 *
 * The paper league changed its criterion week to week and evaluated it by
 * hand; this enum is that tradition made computable. Every metric resolves
 * to one number the entrants predicted: the points metrics straight off
 * the games row the moment it goes final, the yardage metrics from the
 * box-score lines cfb:summaries brings in. A metric whose data has not
 * landed yet resolves to null — settlement falls back to a shared win
 * rather than inventing a number, the same no-defaults rule as everywhere.
 *
 * Labels and questions are FACTUAL vocabulary — a criterion is an
 * instruction, and the voice stays out of it.
 */
enum TiebreakerMetric: string
{
    case CombinedPoints = 'combined_points';

    case TeamPoints = 'team_points';

    case PassingYards = 'passing_yards';

    case RushingYards = 'rushing_yards';

    public function label(): string
    {
        return match ($this) {
            self::CombinedPoints => 'Combined points',
            self::TeamPoints => 'Points scored',
            self::PassingYards => 'Passing yards',
            self::RushingYards => 'Rushing yards',
        };
    }

    /** Whether the question is about ONE side rather than the whole game. */
    public function needsTeam(): bool
    {
        return $this !== self::CombinedPoints;
    }

    /**
     * A ceiling on sane predictions — generous, because the record book is
     * not the validator's business; nonsense is.
     */
    public function maxPrediction(): int
    {
        return match ($this) {
            self::CombinedPoints, self::TeamPoints => 200,
            self::PassingYards, self::RushingYards => 999,
        };
    }

    /**
     * The ANSWER to the week's question, from data the app already holds —
     * or null when it cannot be answered honestly: the game is not final,
     * the box score has not synced, or the stat is absent. Null means the
     * tiebreak is SKIPPED at settlement (tied winners share), never that
     * the answer was zero.
     */
    public function resolveActual(Slate $slate): ?int
    {
        $game = $slate->tiebreakerGame?->game;

        if ($game === null || ! $game->completed) {
            return null;
        }

        return match ($this) {
            self::CombinedPoints => $game->home_score + $game->away_score,
            self::TeamPoints => match ($slate->tiebreaker_team_id) {
                $game->home_team_id => $game->home_score,
                $game->away_team_id => $game->away_score,
                default => null,
            },
            self::PassingYards => self::teamStat($game, $slate->tiebreaker_team_id, 'netPassingYards'),
            self::RushingYards => self::teamStat($game, $slate->tiebreaker_team_id, 'rushingYards'),
        };
    }

    /**
     * One stat off the team's box-score line, by NAME — the ESPN key
     * vocabulary game_team_stats stores. Absent box score or absent key is
     * null: cfb:summaries may lag a final by hours, and the settle sweep
     * simply finds the stat on a later pass — or never, and the tiebreak
     * skips.
     */
    private static function teamStat(Game $game, ?int $teamId, string $key): ?int
    {
        if ($teamId === null) {
            return null;
        }

        // first() rather than value(): the query builder bypasses the
        // model's array cast and would hand back a JSON string.
        $stats = GameTeamStat::query()
            ->where(['game_id' => $game->id, 'team_id' => $teamId])
            ->first()
            ?->stats;

        $stat = $stats[$key] ?? null;

        return is_numeric($stat) ? (int) $stat : null;
    }

    /**
     * The question as the sheet asks it: "Combined points — Clemson at LSU"
     * or "Passing yards — Auburn". Callers pass the tiebreaker game with
     * its game+teams loaded, and the team for one-sided metrics.
     */
    public function question(SlateGame $tiebreakerGame, ?Team $team = null): string
    {
        if ($this->needsTeam()) {
            return $this->label().' — '.($team?->placeName() ?? 'TBD');
        }

        $game = $tiebreakerGame->game;

        return $this->label().' — '
            .($game->awayTeam?->placeName() ?? 'TBD')
            .' at '
            .($game->homeTeam?->placeName() ?? 'TBD');
    }
}
