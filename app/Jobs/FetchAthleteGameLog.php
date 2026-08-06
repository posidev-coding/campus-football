<?php

namespace App\Jobs;

use App\Jobs\Middleware\ThrottleEspn;
use App\Models\Athlete;
use App\Services\Espn\Sync\SyncAthleteStats;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One athlete's game log, as one job.
 *
 * The player page used to fetch this inline, which put an upstream round trip
 * in front of a reader who only wanted to see the page. Opening a page now
 * DISPATCHES this and renders what we already hold; the log arrives behind it.
 *
 * Unique on the ATHLETE, so a player who trends after a big game costs one
 * request rather than one per viewer — the same shape as SyncTeamNews, where a
 * team gaining 500 followers after an upset is one fetch, not 500.
 */
class FetchAthleteGameLog implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Must stay below the queue's `retry_after` (90s) — see FetchGameSummary. */
    public int $timeout = 60;

    public int $tries = 3;

    /**
     * The crash ceiling only — the lock releases on completion. Kept below the
     * gameday window so a worker dying mid-job cannot wedge an athlete out of
     * refreshing for longer than the cadence it is meant to protect.
     */
    public int $uniqueFor = 300;

    /**
     * `$force` skips the staleness check for a refresh the reader asked for by
     * hand. The service's in-flight lock still applies, but that lock is now
     * released when the fetch completes rather than sat on for a minute — so it
     * only ever blocks a genuinely concurrent fetch, never a second click.
     */
    public function __construct(public int $athleteId, public bool $force = false) {}

    public function uniqueId(): string
    {
        return (string) $this->athleteId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * Release rather than sleep when the ESPN allowance is spent — a worker
     * that sits in a usleep loop hits its own timeout mid-wait.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new ThrottleEspn];
    }

    public function handle(SyncAthleteStats $stats): void
    {
        $athlete = Athlete::find($this->athleteId);

        if ($athlete === null) {
            return;
        }

        // Re-checked here rather than trusting the dispatcher: several views
        // can queue this before the first one runs, and the window may have
        // closed in between. A hand-asked refresh says to go anyway.
        if (! $this->force && ! $stats->isStale($athlete)) {
            return;
        }

        // Stamped only when ESPN actually answered. An empty answer still
        // counts — that is how a player with no stats stops being re-queued on
        // every view — but a failed request leaves the timestamp alone so the
        // next view tries again.
        if ($stats->refreshGameLog($this->athleteId)) {
            $athlete->forceFill(['game_log_fetched_at' => now()])->save();
        }
    }
}
