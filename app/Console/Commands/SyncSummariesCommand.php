<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\GameSummary;
use App\Services\Espn\EspnClient;
use App\Services\Espn\Sync\SyncGameSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Box scores for completed games.
 *
 * This is the most expensive feed in the application: one request per game, and
 * each response is 544 KB. It is also the most valuable, because it is the only
 * source of box scores, scoring plays, drives — and of historical players, since
 * ESPN's roster endpoint only ever publishes the current season.
 *
 * A final game is fetched exactly once. `--missing` is therefore the normal way
 * to run this: it picks up only games with no summary yet, so re-running after
 * an interruption resumes rather than restarts.
 */
class SyncSummariesCommand extends Command
{
    protected $signature = 'cfb:summaries
        {--year= : Season year (defaults to CFB_SEASON)}
        {--game= : One game id}
        {--limit=250 : Maximum games this run}
        {--missing : Only games with no summary stored}
        {--force : Refetch even when a summary already exists}';

    protected $description = 'Sync box scores, scoring plays and drives for completed games';

    public function handle(EspnClient $espn, SyncGameSummary $sync): int
    {
        $espn->resetCallCount();
        $started = microtime(true);

        $games = $this->games();

        if ($games->isEmpty()) {
            $this->info('Nothing to sync.');

            return self::SUCCESS;
        }

        $this->info("Syncing {$games->count()} game summaries");
        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $synced = 0;
        $failed = 0;

        foreach ($games as $game) {
            // Deliberately the unthrottled path: the throttle exists to stop
            // page views stampeding one game, and a sequential backfill is
            // already paced by the client's process-wide rate limiter.
            $sync->handle($game) ? $synced++ : $failed++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line(sprintf(
            '  <fg=green>✓</> %d synced%s  <fg=gray>%d requests, %.1fs</>',
            $synced,
            $failed > 0 ? ", <fg=yellow>{$failed} returned no data</>" : '',
            $espn->callCount(),
            microtime(true) - $started
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Game>
     */
    private function games()
    {
        if ($gameId = $this->option('game')) {
            return Game::whereKey((int) $gameId)->get();
        }

        $query = Game::query()
            ->completed()
            ->with('season:id,year')
            ->orderByDesc('kickoff_at')
            ->limit((int) $this->option('limit'));

        if ($year = $this->option('year')) {
            $query->whereHas('season', fn ($q) => $q->where('year', (int) $year));
        }

        if ($this->option('missing') || ! $this->option('force')) {
            $query->whereNotIn('id', GameSummary::select('game_id'));
        }

        return $query->get();
    }
}
