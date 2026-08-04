<?php

namespace App\Jobs;

use App\Jobs\Middleware\ThrottleEspn;
use App\Services\Espn\Sync\SyncCoaches;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One coach, as one job — the same fan-out doctrine as game summaries.
 *
 * A coach costs 2 + 2N requests (Riley is 9 seasons, Smart 10), so the
 * backfill across 136 head coaches is roughly 3,000 requests — about 13
 * minutes against the shared 240/min ceiling however many workers run. The
 * job buys ISOLATION, which is the part that matters: one coach with a
 * malformed season document must not abort the other 135, which is exactly
 * how a single unknown position id once killed a whole stats backfill.
 */
class FetchCoach implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Queueable;

    /** ~22 throttled requests for the longest-tenured coach, well inside 90s retry_after. */
    public int $timeout = 75;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(
        public int $coachId,
        public bool $currentSeasonOnly = false,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->coachId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new ThrottleEspn];
    }

    public function handle(SyncCoaches $sync): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $sync->handle($this->coachId, $this->currentSeasonOnly);
    }
}
