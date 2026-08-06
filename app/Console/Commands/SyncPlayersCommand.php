<?php

namespace App\Console\Commands;

use App\Jobs\SyncTeamSeason;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Services\CfbCalendar;
use App\Services\Espn\EspnClient;
use App\Services\Espn\Sync\SyncRosters;
use App\Services\Espn\Sync\SyncTeamStats;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * The player layer, which is the largest recurring sync in the project.
 *
 * Cost is kept down by never fetching an athlete individually. Rosters come one
 * team at a time and arrive complete — headshot, measurables, class, hometown —
 * so ~16,000 players cost 136 requests rather than 16,000. Stats and leaders
 * add two more per team.
 *
 * Queues a job per TEAM by default. Not for speed — the whole weekly pass is a
 * few hundred requests either way — but so that one team cannot take the other
 * 135 with it. That is a real failure, not a hypothetical: a single historical
 * athlete carrying a position id we did not have aborted the entire 2022 stats
 * backfill on a foreign key.
 *
 * Game logs are deliberately absent: those are per-athlete, so they are fetched
 * on demand when someone opens a player page, and cached.
 */
class SyncPlayersCommand extends Command
{
    protected $signature = 'cfb:players
        {--year= : Season year, or current|results resolved at run time (defaults to CFB_SEASON)}
        {--only= : rosters|stats}
        {--team= : Limit to one team id}
        {--classification=FBS : FBS, FCS, or empty for everything}
        {--now : Run in-process instead of queueing}';

    protected $description = 'Queue roster and team statistics syncs, one job per team';

    public function handle(EspnClient $espn, SyncRosters $rosters, SyncTeamStats $stats): int
    {
        $year = app(CfbCalendar::class)->resolveYear($this->option('year'));
        $only = $this->option('only');

        $teamIds = $this->teamIds($year);

        if ($teamIds === []) {
            $this->error('No teams matched.');

            return self::FAILURE;
        }

        // One team is not worth a queue round trip.
        if ($this->option('now') || count($teamIds) === 1) {
            return $this->runNow($teamIds, $year, $only, $espn, $rosters, $stats);
        }

        $batch = Bus::batch(array_map(
            fn (int $id) => new SyncTeamSeason(
                teamId: $id,
                year: $year,
                rosters: $only !== 'stats',
                stats: $only !== 'rosters',
            ),
            $teamIds
        ))
            ->name("Team {$only} {$year}")
            // One bad team must not cancel the rest.
            ->allowFailures()
            ->dispatch();

        $this->info(sprintf('Queued %d teams for %d.', count($teamIds), $year));
        $this->line("  <fg=gray>batch</> {$batch->id}");

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $teamIds
     */
    private function runNow(
        array $teamIds,
        int $year,
        ?string $only,
        EspnClient $espn,
        SyncRosters $rosters,
        SyncTeamStats $stats
    ): int {
        $espn->resetCallCount();
        $started = microtime(true);

        $count = 0;
        $failed = 0;

        foreach ($teamIds as $teamId) {
            try {
                $count += ($only === 'stats' ? 0 : $rosters->team($teamId, $year))
                    + ($only === 'rosters' ? 0 : $stats->team($teamId, $year));
            } catch (\Throwable $e) {
                $failed++;
                $this->line("    <fg=red>✗</> team {$teamId}: ".mb_substr($e->getMessage(), 0, 100));
            }
        }

        $this->line(sprintf(
            '  <fg=green>✓</> %d records%s  <fg=gray>%d requests, %.1fs</>',
            $count,
            $failed > 0 ? ", <fg=red>{$failed} failed</>" : '',
            $espn->callCount(),
            microtime(true) - $started
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function teamIds(int $year): array
    {
        if ($team = $this->option('team')) {
            return Team::whereKey((int) $team)->pluck('id')->all();
        }

        $classification = $this->option('classification');

        return TeamSeason::where('season_year', $year)
            ->when($classification !== '', fn ($q) => $q->where('classification', $classification))
            ->pluck('team_id')
            ->unique()
            ->values()
            ->all();
    }
}
