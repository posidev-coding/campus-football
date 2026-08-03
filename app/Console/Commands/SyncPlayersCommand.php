<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Espn\EspnClient;
use App\Services\Espn\Sync\SyncRosters;
use App\Services\Espn\Sync\SyncTeamStats;
use Illuminate\Console\Command;

/**
 * The player layer, which is the largest sync in the project.
 *
 * Cost is kept down by never fetching an athlete individually. Rosters come one
 * team at a time and arrive complete — headshot, measurables, class, hometown —
 * so ~16,000 players cost 136 requests rather than 16,000. Stats and leaders
 * add two more per team.
 *
 * Game logs are deliberately absent: those are per-athlete, so they are fetched
 * on demand when someone opens a player page, and cached.
 */
class SyncPlayersCommand extends Command
{
    protected $signature = 'cfb:players
        {--year= : Season year (defaults to CFB_SEASON)}
        {--only= : rosters|stats}
        {--team= : Limit to one team id}
        {--classification=FBS : FBS, FCS, or empty for everything}';

    protected $description = 'Sync rosters, team statistics, and statistical leaders';

    public function handle(EspnClient $espn, SyncRosters $rosters, SyncTeamStats $stats): int
    {
        $year = (int) ($this->option('year') ?: config('cfb.season'));
        $only = $this->option('only');
        $classification = $this->option('classification');
        $team = $this->option('team');

        $espn->resetCallCount();
        $started = microtime(true);

        if ($team !== null) {
            $teamId = (int) $team;

            if (! Team::whereKey($teamId)->exists()) {
                $this->error("No team with id [{$teamId}].");

                return self::FAILURE;
            }

            $count = ($only === 'stats' ? 0 : $rosters->team($teamId, $year))
                + ($only === 'rosters' ? 0 : $stats->team($teamId, $year));
        } else {
            $count = ($only === 'stats' ? 0 : $rosters->handle($year, $classification))
                + ($only === 'rosters' ? 0 : $stats->handle($year, $classification));
        }

        $this->line(sprintf(
            '  <fg=green>✓</> %d records  <fg=gray>%d requests, %.1fs</>',
            $count,
            $espn->callCount(),
            microtime(true) - $started
        ));

        return self::SUCCESS;
    }
}
