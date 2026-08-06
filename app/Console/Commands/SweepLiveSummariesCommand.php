<?php

namespace App\Console\Commands;

use App\Jobs\FetchGameSummary;
use App\Models\Game;
use Illuminate\Console\Command;

/**
 * Keep every LIVE game's box score hydrated, not just the ones being watched.
 *
 * Runs every two minutes inside the live window and queues one summary fetch
 * per in-progress game whose stored summary is due one. The scheduler tick is
 * free when nothing is live — the first query is the guard, the same shape as
 * SyncGames::live() — and the upstream cost is bounded by the count of
 * concurrently live games: a peak Saturday slate is ~30 games, so the sweep
 * spends well under the shared 240/min ESPN budget.
 *
 * Dispatched INDIVIDUALLY, never as a Bus::batch — batched jobs skip
 * ShouldBeUnique locks, and uniqueness is the first layer of the guarantee
 * that a sweep pass and a page full of viewers cannot stack fetches for one
 * game. The job re-checks staleness itself, so this command's SQL filter is
 * an optimization, not the authority.
 */
class SweepLiveSummariesCommand extends Command
{
    protected $signature = 'cfb:summaries:live';

    protected $description = 'Queue summary refreshes for in-progress games with stale box scores';

    public function handle(): int
    {
        // The SQL mirror of SyncGameSummary::isStale() for LIVE games: no
        // summary yet, never synced, or synced over a minute ago. `is_final`
        // cannot be true for an in-progress game, so it needs no clause here.
        $gameIds = Game::query()
            ->inProgress()
            ->where(fn ($query) => $query
                ->whereDoesntHave('summary')
                ->orWhereHas('summary', fn ($summary) => $summary
                    ->whereNull('synced_at')
                    ->orWhere('synced_at', '<', now()->subMinute())))
            ->pluck('id');

        $gameIds->each(fn (int $id) => FetchGameSummary::dispatch($id)->onQueue('live'));

        $this->info("Queued {$gameIds->count()} live summary refreshes.");

        return self::SUCCESS;
    }
}
