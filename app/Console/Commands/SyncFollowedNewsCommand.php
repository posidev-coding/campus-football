<?php

namespace App\Console\Commands;

use App\Console\Concerns\TracksFeedRun;
use App\Services\Espn\Sync\SyncNews;
use Illuminate\Console\Command;

/**
 * Refresh the per-team news feed for every team somebody actually follows.
 *
 * 136 FBS teams is too many to refresh blindly for content nobody has asked to
 * see, so this spends one request per FOLLOWED team and everyone else's team
 * page fetches on demand and caches. Cost tracks interest — that is settled
 * design, not an oversight, and nothing here caps or chunks the pass.
 *
 * It exists as a command rather than as the `Schedule::call()` closure it used
 * to be for one reason: a closure can carry no `TracksFeedRun`, so the two
 * schedule entries read `last_status: null` on Sync Health forever, whether the
 * sweep ran, failed, or quietly synced nothing. The row is the whole point —
 * a pass that finds no followed teams still writes `records: 0`, which is what
 * tells "ran and found nothing" apart from "never ran".
 */
class SyncFollowedNewsCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'cfb:news:followed';

    protected $description = 'Refresh the ESPN news feed for every followed team';

    public function handle(SyncNews $news): int
    {
        // No early return above this line, ever: the empty pass is exactly the
        // one the ledger has to be able to report on.
        $synced = $this->trackRun('news:followed', null, fn (): int => $news->followed());

        $this->info("Synced {$synced} followed-team articles.");

        return self::SUCCESS;
    }
}
