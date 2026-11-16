<?php

namespace App\Console\Commands;

use App\Console\Concerns\TracksFeedRun;
use App\Jobs\FetchGameSummary;
use App\Models\Game;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
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
 * Each dispatch is ISOLATED, the same doctrine as the jobs themselves (see
 * FetchCoach) and the scoreboard's per-event loop. A dispatch is a write to
 * the queue store, and one that throws on game three of twelve must cost game
 * three, not games four through twelve. So every game is tried, and THEN the
 * run fails, naming each casualty: the feed run is recorded failed and the
 * exit code is non-zero, the same as SyncGames after a partial pass, so a game
 * that stalls on every tick shows up in Sync Health instead of hiding behind
 * a run marked complete. No retry: the next tick two minutes later is the
 * retry, and the live tier's budget has no room for a second attempt here.
 */
class SweepLiveSummariesCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'cfb:summaries:live';

    protected $description = 'Queue summary refreshes for in-progress games with stale box scores';

    public function handle(): int
    {
        $queued = $this->trackRun('summaries:live', null, function (): int {
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
                $job = (new FetchGameSummary($id))->onQueue('live');

                try {
                    dispatch($job);
                    $queued++;
                } catch (Throwable $e) {
                    report($e);
                    $failures[] = "game {$id}: {$e->getMessage()}";
                    $this->releaseUniqueLock($job);
                }
            }

            if ($failures !== []) {
                throw new RuntimeException(sprintf(
                    '%d of %d live summary dispatches failed (%d queued). %s',
                    count($failures),
                    $gameIds->count(),
                    $queued,
                    implode('; ', $failures),
                ));
            }

            return $queued;
        });

        $this->info("Queued {$queued} live summary refreshes.");

        return self::SUCCESS;
    }

    /**
     * Give back the unique lock a failed dispatch took.
     *
     * PendingDispatch takes FetchGameSummary's lock (uniqueFor 300s) BEFORE it
     * pushes, and nothing releases it when the push throws. Left alone, the
     * next two ticks would skip the game as already queued when it never was.
     * Only a lock this dispatch OWNS is released: if acquiring it was what
     * threw, the owner is empty and the lock may be a viewer's pending fetch.
     */
    private function releaseUniqueLock(FetchGameSummary $job): void
    {
        if ($job->uniqueLockOwner === '') {
            return;
        }

        try {
            (new UniqueLock(app(Cache::class)))->release($job);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
