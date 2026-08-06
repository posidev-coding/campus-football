<?php

namespace App\Jobs;

use App\Jobs\Middleware\ThrottleEspn;
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

    /**
     * `$force` skips the staleness re-check, never the in-flight lock: the
     * just-final fetch and the backfill must run even when a live fetch
     * landed seconds ago, because what they are fetching is the FINAL truth.
     * Live dispatches (sweep, page views) leave it false, so a copy that sat
     * queued behind an equivalent fetch becomes a no-op instead of a request.
     */
    public function __construct(public int $gameId, public bool $force = false) {}

    public function uniqueId(): string
    {
        // The game id alone — force and non-force copies dedupe together,
        // deliberately: two fetches for one game inside one window are
        // redundant whichever flavor got there first.
        return (string) $this->gameId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * Release rather than sleep when the ESPN allowance is spent.
     *
     * Without this, a worker that finds the limiter full sits in a usleep loop
     * until the fixed window rolls — up to a minute — and hits its own 60s
     * timeout mid-wait. Throughput would go DOWN as workers were added.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new ThrottleEspn];
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

        // Re-checked here rather than trusting the dispatcher: many viewers
        // and the sweep can queue this game before the first copy runs, and
        // uniqueness cannot dedupe a dispatch made after an earlier copy
        // finished. A fresh summary makes the late copy a no-op.
        if (! $this->force && ! $sync->isStale($game)) {
            return;
        }

        $sync->handle($game);
    }
}
