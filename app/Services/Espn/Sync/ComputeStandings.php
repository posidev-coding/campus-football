<?php

namespace App\Services\Espn\Sync;

use App\Enums\StandingSource;
use App\Models\Game;
use App\Models\Season;
use App\Models\Standing;
use App\Models\TeamSeason;
use Illuminate\Support\Facades\DB;

/**
 * Derives standings from our own completed games, independent of ESPN's feed.
 *
 * This is not redundancy for its own sake. ESPN's standings endpoint is the
 * authority, but a feed can regress silently — a shape change, a mid-season
 * re-parenting, a conference that quietly stops publishing. Without a second
 * opinion there is nothing to compare against and the first sign of trouble is
 * a user reporting wrong records.
 *
 * Conference membership is read from `team_seasons`, so a game only counts
 * toward a conference record when BOTH teams were in that conference in that
 * season — which is exactly the calculation realignment used to break.
 */
class ComputeStandings
{
    /**
     * @param  int  $seasonType  Must match the season type the ESPN standings
     *                           were pulled from, or the two sources are not
     *                           comparable.
     */
    public function handle(int $year, int $seasonType = Season::REGULAR): int
    {
        $membership = TeamSeason::where('season_year', $year)
            ->whereNotNull('conference_id')
            ->pluck('conference_id', 'team_id');

        if ($membership->isEmpty()) {
            return 0;
        }

        $tallies = [];

        Game::query()
            ->completed()
            /*
             * Regular season only.
             *
             * ESPN's types/2 standings stop at the end of the regular season,
             * but our games table spans the CFP. Counting playoff results here
             * made Indiana read 16-0 against ESPN's 13-0 and flagged five
             * playoff teams as diverged — a bug in this computation, caught by
             * the reconciler doing its job.
             */
            ->whereHas('season', fn ($q) => $q->where('year', $year)->where('type', $seasonType))
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            // `id` is required by chunkById for cursor pagination, not just
            // for the tally.
            ->select([
                'id', 'home_team_id', 'away_team_id', 'home_score', 'away_score',
            ])
            ->chunkById(500, function ($games) use (&$tallies, $membership) {
                foreach ($games as $game) {
                    $this->tallyGame($game, $tallies, $membership);
                }
            }, 'id');

        return $this->persist($tallies, $year);
    }

    private function tallyGame(Game $game, array &$tallies, $membership): void
    {
        $home = $game->home_team_id;
        $away = $game->away_team_id;

        $homeConf = $membership[$home] ?? null;
        $awayConf = $membership[$away] ?? null;

        // A conference game is one where both participants belong to the same
        // conference *in this season*.
        $isConferenceGame = $homeConf !== null && $homeConf === $awayConf;

        foreach ([[$home, $game->home_score, $game->away_score, $homeConf],
            [$away, $game->away_score, $game->home_score, $awayConf]] as [$teamId, $scored, $allowed, $conf]) {

            if ($conf === null) {
                continue;
            }

            $tallies[$teamId] ??= $this->emptyTally($conf);

            $tallies[$teamId]['points_for'] += $scored;
            $tallies[$teamId]['points_against'] += $allowed;

            $result = $scored <=> $allowed;

            $bucket = match ($result) {
                1 => 'wins',
                -1 => 'losses',
                default => 'ties',
            };

            $tallies[$teamId]['overall_'.$bucket]++;

            if ($isConferenceGame) {
                $tallies[$teamId]['conf_'.$bucket]++;
            }
        }
    }

    private function emptyTally(int $conferenceId): array
    {
        return [
            'conference_id' => $conferenceId,
            'overall_wins' => 0, 'overall_losses' => 0, 'overall_ties' => 0,
            'conf_wins' => 0, 'conf_losses' => 0, 'conf_ties' => 0,
            'points_for' => 0, 'points_against' => 0,
        ];
    }

    private function persist(array $tallies, int $year): int
    {
        if ($tallies === []) {
            return 0;
        }

        DB::transaction(function () use ($tallies, $year) {
            foreach ($tallies as $teamId => $tally) {
                $overallGames = $tally['overall_wins'] + $tally['overall_losses'] + $tally['overall_ties'];
                $confGames = $tally['conf_wins'] + $tally['conf_losses'] + $tally['conf_ties'];

                Standing::updateOrCreate(
                    [
                        'season_year' => $year,
                        'conference_id' => $tally['conference_id'],
                        'team_id' => $teamId,
                        'source' => StandingSource::Computed,
                    ],
                    [
                        'overall_wins' => $tally['overall_wins'],
                        'overall_losses' => $tally['overall_losses'],
                        'overall_ties' => $tally['overall_ties'],
                        'conf_wins' => $tally['conf_wins'],
                        'conf_losses' => $tally['conf_losses'],
                        'conf_ties' => $tally['conf_ties'],
                        'win_pct' => $overallGames > 0
                            ? round($tally['overall_wins'] / $overallGames, 4)
                            : null,
                        'conf_win_pct' => $confGames > 0
                            ? round($tally['conf_wins'] / $confGames, 4)
                            : null,
                        'points_for' => $tally['points_for'],
                        'points_against' => $tally['points_against'],
                        'point_differential' => $tally['points_for'] - $tally['points_against'],
                        'synced_at' => now(),
                    ]
                );
            }
        });

        return count($tallies);
    }
}
