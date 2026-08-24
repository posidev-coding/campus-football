<?php

namespace App\Jobs;

use App\Jobs\Middleware\ThrottleMail;
use App\Jobs\Middleware\ThrottleSms;
use App\Models\User;
use App\Notifications\PickReminderNotification;
use App\Support\PickReminders;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One reader's "your picks are due".
 *
 * Per USER rather than per send, so one missing group or null anywhere costs
 * that reader their reminder and nobody else theirs — the isolation the
 * newsletter fan-out already buys, and the reason its batch allows failures.
 *
 * `Batchable` is explicit: Laravel's Queueable does NOT include it, and
 * `$this->batch()` on a job without it is a fatal at run time.
 */
class SendPickReminder implements ShouldQueue
{
    use Batchable, Queueable;

    /** Must stay below the queue's `retry_after` (90s). */
    public int $timeout = 60;

    /**
     * ThrottleMail RELEASES an over-budget job, and a release still burns
     * an attempt: at the worker default (--tries=1) the throttled tail of
     * any send bigger than the daily budget was deleted, not delayed. The
     * stale-reminder guard stays in handle() — a retry that outlives its
     * cards sends nothing.
     */
    public int $tries = 5;

    /**
     * @param  list<int>  $slateIds  the cards this reader owed when the sweep ran
     */
    public function __construct(
        public int $userId,
        public array $slateIds,
        public string $wave,
    ) {}

    /**
     * Both budgets. ThrottleSms is inert while `via()` omits the channel, and
     * correct on the day it does not — attaching it now costs nothing and
     * means the guard is not being added under pressure alongside the first
     * real text.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new ThrottleMail, new ThrottleSms];
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

        /*
         * RE-CHECKED HERE, and this is the load-bearing part of the job.
         *
         * ThrottleMail RELEASES rather than drops, so a reminder can sit in
         * the queue until tomorrow. A weekly digest arriving a day late is
         * merely late; a pick reminder arriving after kickoff is WORSE THAN
         * NONE — it tells somebody to do a thing the app will refuse, about
         * games that have already been played.
         *
         * So the cards are rebuilt from live data rather than trusted from
         * the payload: anything picked since, anything kicked off since, and
         * any membership dropped since is gone by construction. An empty
         * result means the reminder no longer has anything true to say, and
         * silence is the right failure direction.
         */
        $cards = PickReminders::cardsFor($user, $this->slateIds);

        if ($cards === []) {
            return;
        }

        $user->notify(new PickReminderNotification($cards, $this->wave));
    }
}
