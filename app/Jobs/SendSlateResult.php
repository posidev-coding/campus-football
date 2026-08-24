<?php

namespace App\Jobs;

use App\Jobs\Middleware\ThrottleMail;
use App\Models\Slate;
use App\Models\User;
use App\Notifications\SlateMissed;
use App\Notifications\SlateSettled;
use App\Support\SlateResults;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One reader's result.
 *
 * Per user for the isolation the newsletter fan-out already buys — and
 * because the daily mail budget only counts when it is job middleware, so a
 * single job for the whole room would spend nothing and mean nothing.
 *
 * A settled slate is immutable history, so unlike the pick reminder there
 * is nothing here that can go stale between dispatch and run. What IS
 * re-checked is consent and membership: somebody who opts out or leaves
 * while the batch drains should not still receive it.
 */
class SendSlateResult implements ShouldQueue
{
    use Batchable, Queueable;

    /** Must stay below the queue's `retry_after` (90s). */
    public int $timeout = 60;

    /**
     * ThrottleMail RELEASES an over-budget job, and a release still burns
     * an attempt: at the worker default (--tries=1) the throttled tail of
     * any send bigger than the daily budget was deleted, not delayed.
     */
    public int $tries = 5;

    public function __construct(public int $slateId, public int $userId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleMail];
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        $slate = Slate::query()
            ->with(['contest.group', 'entries.user', 'week'])
            ->find($this->slateId);

        if ($slate === null) {
            return;
        }

        $result = SlateResults::forUser($slate, $user);

        if ($result === null) {
            return;
        }

        $result['slate_id'] = $slate->id;

        $user->notify($result['entered']
            ? new SlateSettled($result)
            : new SlateMissed($result));
    }
}
