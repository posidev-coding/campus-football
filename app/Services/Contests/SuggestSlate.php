<?php

namespace App\Services\Contests;

use App\Models\Contest;
use App\Models\Game;
use App\Models\Week;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The commissioner's pre-filled board: the week's best slate-eligible games
 * by quality score, tiered when the mode tiers. A suggestion is a starting
 * point — the builder lets the commissioner swap every game — and house
 * lobbies publish it unedited through the same PublishSlate door.
 *
 * Group affinity is applied HERE rather than inside GameQualityScore, so
 * the base score stays a per-game fact while "your people care about this
 * one" stays a per-group opinion.
 *
 * May return fewer rows than the mode wants on a thin week — publish
 * validation is the gate that refuses a short board, and a loud shortfall
 * beats quietly padding with spreadless games.
 */
class SuggestSlate
{
    /** What a game either side of which the group follows gains. */
    private const AFFINITY_BONUS = 8.0;

    /**
     * @return list<array<string, mixed>> one entry per suggested game:
     *                                    game_id, score, tier, plus ContestLine::seedValues() verbatim
     */
    public function for(Contest $contest, Week $week): array
    {
        $engine = $contest->mode->engine($contest->settings);
        $size = $engine->slateSize();
        $spec = $engine->tierSpec();

        $candidates = Game::query()
            ->slateEligible()
            ->where('week_id', $week->id)
            ->upcoming()
            ->with(['odds', 'predictor'])
            ->get()
            // The time-of-day half of the window is a per-game check — the
            // ET boundary is not safely expressible in SQL.
            ->filter(fn (Game $game) => $game->inSlateWindow());

        $followed = $this->followedTeamIds($contest);

        $scored = [];

        foreach ($candidates as $game) {
            $score = GameQualityScore::for($game);

            // Null is "cannot be slated", not a zero — skip, never rank.
            if ($score === null) {
                continue;
            }

            if ($followed->contains($game->home_team_id) || $followed->contains($game->away_team_id)) {
                $score += self::AFFINITY_BONUS;
            }

            // Everything the row needs, half-point law applied — the
            // builder writes these verbatim so a suggested board is
            // publishable as suggested.
            $scored[] = [
                'game_id' => $game->id,
                'score' => round($score, 2),
                'tier' => null,
                ...ContestLine::seedValues($game),
            ];
        }

        usort($scored, fn (array $a, array $b) => [$b['score'], $a['game_id']] <=> [$a['score'], $b['game_id']]);

        $board = array_slice($scored, 0, $size);

        if ($spec !== null) {
            $board = $this->assignTiers($board, $spec);
        }

        return $board;
    }

    /**
     * Rank order fills tiers top-down: the spec's first tier takes the best
     * games, and so on — tier 1 IS "the best five" by construction.
     *
     * @param  list<array<string, mixed>>  $board
     * @param  array<int, int>  $spec
     * @return list<array<string, mixed>>
     */
    private function assignTiers(array $board, array $spec): array
    {
        $index = 0;

        foreach ($spec as $tier => $count) {
            for ($i = 0; $i < $count && $index < count($board); $i++, $index++) {
                $board[$index]['tier'] = $tier;
            }
        }

        return $board;
    }

    /**
     * Every team any member of the contest's group follows. A pivot-to-pivot
     * join, which Eloquent has no relation shape for — the query builder is
     * the honest tool here.
     */
    private function followedTeamIds(Contest $contest): Collection
    {
        return DB::table('team_follows')
            ->join('group_members', 'group_members.user_id', '=', 'team_follows.user_id')
            ->where('group_members.group_id', $contest->group_id)
            ->distinct()
            ->pluck('team_follows.team_id');
    }
}
