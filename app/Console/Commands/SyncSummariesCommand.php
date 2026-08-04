<?php

namespace App\Console\Commands;

use App\Jobs\FetchGameSummary;
use App\Models\Game;
use App\Models\GameSummary;
use App\Services\Espn\EspnClient;
use App\Services\Espn\Sync\SyncGameSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

/**
 * Box scores for completed games.
 *
 * The most expensive feed in the application — one request per game, 544 KB
 * each — and the only source of box scores, scoring plays, drives, and of
 * historical players, since ESPN's roster endpoint publishes the current
 * season only.
 *
 * Dispatches a JOB PER GAME by default rather than looping in-process. The
 * sequential version died at PHP's 128 MB limit partway through a 693-game run;
 * a queue frees memory between jobs, retries a single game instead of losing
 * the run, and can be watched and cancelled. Upstream cost is unchanged because
 * EspnClient's throttle is shared through the cache.
 *
 * A final game is fetched exactly once, so `--missing` makes every re-run a
 * resume rather than a restart.
 */
class SyncSummariesCommand extends Command
{
    protected $signature = 'cfb:summaries
        {--year= : Season year (defaults to every season)}
        {--game= : One game id}
        {--limit=2000 : Maximum games this run}
        {--missing : Only games with no summary stored (default unless --force)}
        {--force : Refetch even when a summary already exists}
        {--now : Run in-process instead of queueing (small runs and debugging)}';

    protected $description = 'Queue box score, scoring play and drive syncs for completed games';

    public function handle(EspnClient $espn, SyncGameSummary $sync): int
    {
        $gameIds = $this->gameIds();

        if ($gameIds->isEmpty()) {
            $this->info('Nothing to sync.');

            return self::SUCCESS;
        }

        return $this->option('now')
            ? $this->runNow($gameIds, $espn, $sync)
            : $this->queue($gameIds);
    }

    /**
     * Fan out to the queue.
     *
     * @param  Collection<int, int>  $gameIds
     */
    private function queue(Collection $gameIds): int
    {
        $batch = Bus::batch($gameIds->map(fn (int $id) => new FetchGameSummary($id))->all())
            ->name('Game summaries'.($this->option('year') ? ' '.$this->option('year') : ''))
            /*
             * One corrupt game must not cancel the rest. ESPN game 401767129
             * carries a scoring play with a negative score; before this, that
             * single row ended a 954-game run at game 260.
             */
            ->allowFailures()
            ->dispatch();

        $this->info("Queued {$gameIds->count()} game summaries.");
        $this->newLine();
        $this->line("  <fg=gray>batch</> {$batch->id}");
        $this->line('  <fg=gray>watch</> php artisan cfb:summaries:status '.$batch->id);
        $this->newLine();
        $this->line('  <fg=gray>Run a worker if one is not already going:</>');
        $this->line('  <fg=gray>php artisan queue:work --stop-when-empty --memory=256</>');

        return self::SUCCESS;
    }

    /**
     * The in-process path, kept for one game and for debugging.
     *
     * @param  Collection<int, int>  $gameIds
     */
    private function runNow(Collection $gameIds, EspnClient $espn, SyncGameSummary $sync): int
    {
        $espn->resetCallCount();
        $started = microtime(true);

        $this->info("Syncing {$gameIds->count()} game summaries in-process");
        $bar = $this->output->createProgressBar($gameIds->count());
        $bar->start();

        $synced = 0;
        $empty = 0;
        $errors = [];

        foreach ($gameIds as $gameId) {
            $game = Game::with('season:id,year')->find($gameId);

            try {
                if ($game !== null) {
                    $sync->handle($game) ? $synced++ : $empty++;
                }
            } catch (\Throwable $e) {
                $errors[$gameId] = $e->getMessage();
            }

            // Released explicitly: this path exists precisely because holding
            // every model alive is what exhausted memory in the first place.
            unset($game);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line(sprintf(
            '  <fg=green>✓</> %d synced%s%s  <fg=gray>%d requests, %.1fs, peak %dMB</>',
            $synced,
            $empty > 0 ? ", <fg=yellow>{$empty} returned no data</>" : '',
            $errors !== [] ? ', <fg=red>'.count($errors).' errored</>' : '',
            $espn->callCount(),
            microtime(true) - $started,
            memory_get_peak_usage(true) / 1048576
        ));

        foreach (array_slice($errors, 0, 5, true) as $gameId => $message) {
            $this->line("    <fg=red>✗</> game {$gameId}: ".mb_substr($message, 0, 120));
        }

        return self::SUCCESS;
    }

    /**
     * Ids only, never models.
     *
     * Hydrating thousands of Game models just to read their primary keys is
     * itself a chunk of the memory this rework exists to avoid.
     *
     * @return Collection<int, int>
     */
    private function gameIds(): Collection
    {
        if ($gameId = $this->option('game')) {
            return Game::whereKey((int) $gameId)->pluck('id');
        }

        $query = Game::query()
            ->completed()
            // Newest first, so the seasons people actually browse fill in
            // before 2021 does.
            ->orderByDesc('kickoff_at')
            ->limit((int) $this->option('limit'));

        if ($year = $this->option('year')) {
            $query->whereHas('season', fn ($q) => $q->where('year', (int) $year));
        }

        if (! $this->option('force')) {
            $query->whereNotIn('id', GameSummary::select('game_id'));
        }

        return $query->pluck('id');
    }
}
