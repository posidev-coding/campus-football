<?php

namespace App\Jobs;

use App\Models\Slate;
use App\Support\SlateResults;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

/**
 * "The week is official" — fan the results out to the room.
 *
 * TAKES ITS OWN CLAIM, and takes it BEFORE building the batch. A queue
 * retry — a timeout, a worker restart, a failure halfway through the fan-out
 * — re-runs this whole job, and without the claim every entrant is mailed a
 * second time. `settled_at` cannot serve: it claims the MONEY, and the two
 * have to be repairable apart. A botched announcement is fixed by nulling
 * `results_announced_at` and re-running; the wallet never hears about it,
 * and no payout can be reissued by anything in this file.
 *
 * The slate id is all this carries. `SettleSlate` dispatches from a point
 * where its own in-memory instance is stale — the claim is a query-builder
 * update — so the id is the only thing there worth trusting, and by the time
 * this runs the row is committed and authoritative.
 */
class AnnounceSlateResults implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $slateId) {}

    public function handle(): void
    {
        $claimed = Slate::query()
            ->whereKey($this->slateId)
            ->whereNotNull('settled_at')
            ->whereNull('results_announced_at')
            ->update(['results_announced_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $slate = Slate::query()
            ->with(['contest.group', 'entries.user', 'games.game'])
            ->find($this->slateId);

        if ($slate === null) {
            return;
        }

        $jobs = SlateResults::audience($slate)
            ->map(fn (array $row) => new SendSlateResult($this->slateId, $row['user_id']))
            ->all();

        if ($jobs === []) {
            return;
        }

        // One bad address must not cancel the rest of the room's results.
        Bus::batch($jobs)
            ->name("Slate results ({$this->slateId})")
            ->allowFailures()
            ->dispatch();
    }
}
