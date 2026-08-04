<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\Espn\Sync\SyncGameSummary;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One game's summary, as one job.
 *
 * The sequential version of this backfill died at 128 MB partway through a
 * 693-game run: memory grew about a megabyte a game and nothing in a single
 * long-lived process ever gave it back. A job per game fixes that structurally
 * rather than by tuning — the worker frees between jobs, and `queue:work
 * --memory` restarts it if it ever does drift.
 *
 * It also buys what a loop cannot: parallelism across workers, retries on a
 * single game rather than the whole run, and a batch that can be resumed,
 * cancelled and watched.
 *
 * Upstream cost does NOT scale with worker count. EspnClient's throttle is
 * backed by the RateLimiter, which is a shared cache — so 1 worker and 10
 * workers both sit under the same 240/min ceiling. That is the property that
 * makes fanning this out safe.
 */
class FetchGameSummary implements ShouldBeUnique, ShouldQueue
{
    // Batchable is separate from Queueable and is what provides `batch()` —
    // without it, checking whether the batch was cancelled is a fatal error at
    // run time rather than a compile-time one.
    use Batchable, Queueable;

    /**
     * Must stay below the queue's `retry_after` (90s).
     *
     * v3 had this backwards — its retry_after was shorter than every job
     * timeout, so long jobs were released back onto the queue and re-run while
     * the first copy was still executing. One request plus its writes is well
     * inside a minute; the relationship is the invariant, not the number.
     */
    public int $timeout = 60;

    public int $tries = 3;

    /**
     * Guards a double dispatch — running the command twice while a batch is
     * still draining. Short enough not to block a legitimate retry: the lock
     * releases on completion anyway, and this is only the crash ceiling.
     */
    public int $uniqueFor = 300;

    public function __construct(public int $gameId) {}

    public function uniqueId(): string
    {
        return (string) $this->gameId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(SyncGameSummary $sync): void
    {
        // Cancelled batch, or the game went between dispatch and execution.
        if ($this->batch()?->cancelled()) {
            return;
        }

        $game = Game::with('season:id,year')->find($this->gameId);

        if ($game === null) {
            return;
        }

        // The unthrottled path: the per-game throttle exists to stop page views
        // stampeding one live game, and a batch is already paced by the
        // client's shared rate limiter.
        $sync->handle($game);
    }
}
