<?php

namespace App\Console\Commands;

use App\Console\Concerns\TracksFeedRun;
use App\Jobs\FetchGameSummary;
use App\Models\Game;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

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
 *
 * Each dispatch is ISOLATED — the same doctrine as the jobs themselves (see
 * FetchCoach). A dispatch is a write to the queue store, and one that throws
 * on game three of twelve must cost game three, not games four through
 * twelve. A failure is reported, counted and written to the run's FeedRun;
 * the run itself fails only when there were live games and not one dispatch
 * landed. No retry: the next tick two minutes later is the retry, and the
 * live tier's budget has no room for a second attempt inside this one.
 */
class SweepLiveSummariesCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'cfb:summaries:live';

    protected $description = 'Queue summary refreshes for in-progress games with stale box scores';

    public function handle(): int
    {
        $failed = 0;

        $queued = $this->trackRun('summaries:live', null, function () use (&$failed): array {
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

            $queued = 0;
            $failures = [];

            foreach ($gameIds as $id) {
                try {
                    FetchGameSummary::dispatch($id)->onQueue('live');
                    $queued++;
                } catch (Throwable $e) {
                    report($e);
                    $failures[] = "game {$id}: {$e->getMessage()}";
                }
            }

            $failed = count($failures);

            if ($failed > 0 && $queued === 0) {
                throw new RuntimeException("No live summary refresh could be queued ({$failed} failed). ".implode('; ', $failures));
            }

            return [
                'records' => $queued,
                'error' => $failed > 0
                    ? "{$failed} of {$gameIds->count()} live summary dispatches failed. ".implode('; ', $failures)
                    : null,
            ];
        });

        $this->info("Queued {$queued} live summary refreshes.");

        if ($failed > 0) {
            $this->warn("{$failed} could not be queued; see the summaries:live feed run.");
        }

        return self::SUCCESS;
    }
}
