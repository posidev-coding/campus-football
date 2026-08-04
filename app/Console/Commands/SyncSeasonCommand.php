<?php

namespace App\Console\Commands;

use App\Services\Espn\EspnClient;
use App\Services\Espn\Sync\ComputeStandings;
use App\Services\Espn\Sync\ReconcileStandings;
use App\Services\Espn\Sync\SyncConferences;
use App\Services\Espn\Sync\SyncGames;
use App\Services\Espn\Sync\SyncInjuries;
use App\Services\Espn\Sync\SyncNationalLeaders;
use App\Services\Espn\Sync\SyncNews;
use App\Services\Espn\Sync\SyncPredictors;
use App\Services\Espn\Sync\SyncRankings;
use App\Services\Espn\Sync\SyncRecruiting;
use App\Services\Espn\Sync\SyncSeason;
use App\Services\Espn\Sync\SyncStandings;
use App\Services\Espn\Sync\SyncTeams;
use Illuminate\Console\Command;

class SyncSeasonCommand extends Command
{
    protected $signature = 'cfb:sync
        {--year= : Season year (defaults to CFB_SEASON)}
        {--only= : One step: seasons|conferences|teams|games|rankings|predictors|recruiting|injuries|standings|compute|reconcile|leaders|athletes|news}';

    protected $description = 'Sync a season of reference data from ESPN';

    /**
     * Order matters and is not negotiable: conferences must exist before teams
     * (teams inherit classification from the conference tree), both must exist
     * before games, and games must exist before predictors (which only fetch
     * for upcoming fixtures) and standings.
     *
     * `leaders` runs before `athletes` because the athlete resolve pass reads
     * the leaderboard to find out who is missing.
     */
    private const STEPS = [
        'seasons', 'conferences', 'teams', 'games',
        'rankings', 'predictors', 'recruiting', 'injuries',
        'standings', 'compute', 'reconcile',
        'leaders', 'athletes', 'news',
    ];

    public function handle(EspnClient $espn): int
    {
        $year = (int) ($this->option('year') ?: config('cfb.season'));
        $only = $this->option('only');

        if ($only !== null && ! in_array($only, self::STEPS, true)) {
            $this->error("Unknown step [{$only}]. Expected one of: ".implode(', ', self::STEPS));

            return self::FAILURE;
        }

        $steps = $only ? [$only] : self::STEPS;

        $this->info("Syncing {$year} from ESPN");
        $espn->resetCallCount();
        $started = microtime(true);

        foreach ($steps as $step) {
            $this->runStep($step, $year);
        }

        $this->newLine();
        $this->line(sprintf(
            '  <fg=gray>%d ESPN requests in %.1fs</>',
            $espn->callCount(),
            microtime(true) - $started
        ));

        return self::SUCCESS;
    }

    private function runStep(string $step, int $year): void
    {
        $started = microtime(true);

        $count = match ($step) {
            'seasons' => count(app(SyncSeason::class)->handle($year)),
            'conferences' => app(SyncConferences::class)->handle($year),
            'teams' => app(SyncTeams::class)->handle($year),
            'games' => app(SyncGames::class)->season($year),
            'rankings' => app(SyncRankings::class)->season($year),
            'predictors' => app(SyncPredictors::class)->upcoming(),
            'recruiting' => app(SyncRecruiting::class)->handle($year),
            'injuries' => app(SyncInjuries::class)->handle($year),
            'standings' => app(SyncStandings::class)->handle($year),
            'compute' => app(ComputeStandings::class)->handle($year),
            'reconcile' => app(ReconcileStandings::class)->handle($year),
            'leaders' => app(SyncNationalLeaders::class)->season($year),
            'athletes' => app(SyncNationalLeaders::class)->resolveAthletes(),
            // News is a rolling few-day window with no season parameter, so it
            // is year-independent — syncing it per year would just refetch the
            // same articles.
            'news' => app(SyncNews::class)->general(),
        };

        $label = match ($step) {
            'reconcile' => $count > 0 ? "{$count} diverged" : 'no divergence',
            'athletes' => "{$count} resolved",
            default => "{$count} records",
        };

        $this->line(sprintf(
            '  <fg=green>✓</> %-12s %-18s <fg=gray>%.1fs</>',
            $step,
            $label,
            microtime(true) - $started
        ));
    }
}
