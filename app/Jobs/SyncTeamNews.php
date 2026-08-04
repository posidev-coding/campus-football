<?php

namespace App\Jobs;

use App\Models\Team;
use App\Services\Espn\Sync\SyncNews;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetch one team's news feed.
 *
 * Dispatched when somebody follows a team or makes it their favourite, because
 * that is the moment a team's history becomes worth having. ESPN's per-team
 * feed is a genuinely different set from the national one — measured live,
 * Alabama's feed carried 25 articles we did not already hold and Miami's 19 —
 * so every new follow deepens coverage rather than re-fetching what we have.
 *
 * Worth being precise about what this does and does not do: it DENSIFIES the
 * window rather than extending it much. ESPN publishes a rolling few weeks per
 * team, so this fills in stories inside that window; it cannot reach back a
 * year. Real long-run history still only accumulates by syncing over time.
 */
class SyncTeamNews implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Deduplicated on the TEAM, not the user.
     *
     * Five hundred people following Georgia in the same minute is one fetch.
     * Without this, a team going viral after an upset would queue one request
     * per follower for a payload identical for all of them.
     */
    public int $uniqueFor = 600;

    /**
     * Must stay below the queue's `retry_after` (90s).
     *
     * This is the v3 bug written down: its retry_after was SHORTER than every
     * job timeout, so long jobs were released back onto the queue and re-run
     * while the first copy was still executing — duplicate concurrent workers
     * hammering the same endpoint. The relationship is the invariant, not the
     * number; changing one means checking the other.
     */
    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(public int $teamId) {}

    public function uniqueId(): string
    {
        return (string) $this->teamId;
    }

    /**
     * Backs off rather than retrying three times inside a few seconds, so a
     * rate-limited or briefly-down ESPN gets room to recover.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(SyncNews $news): void
    {
        // The team may have gone between dispatch and execution, and a missing
        // team is not a failure worth retrying.
        if (! Team::whereKey($this->teamId)->exists()) {
            return;
        }

        /*
         * No throttle beyond uniqueness, deliberately. EspnClient caches this
         * response for the schedule TTL, so even a duplicate that slips past
         * `uniqueFor` is served from cache and costs nothing upstream.
         */
        $news->team($this->teamId);
    }
}
