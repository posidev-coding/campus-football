<?php

namespace App\Jobs;

use App\Jobs\Middleware\ThrottleMail;
use App\Models\User;
use App\Notifications\WeeklyNewsletter;
use App\Support\RecapWriter;
use App\Support\WeeklyDigest;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One reader's weekly email.
 *
 * Per USER rather than per send, so a bad address, a deleted team or a null
 * anywhere in one digest costs that reader their email and nobody else theirs
 * — the same isolation the ESPN fan-outs buy, and the reason the batch that
 * dispatches these uses `allowFailures()`.
 *
 * `Batchable` is explicit: Laravel's Queueable does NOT include it, and
 * `$this->batch()` on a job without it is a fatal at run time rather than a
 * compile-time error.
 */
class SendWeeklyNewsletter implements ShouldQueue
{
    use Batchable, Queueable;

    /** Must stay below the queue's `retry_after` (90s). */
    public int $timeout = 60;

    /**
     * ThrottleMail RELEASES an over-budget job, and a release still burns
     * an attempt: at the worker default (--tries=1) the throttled tail of
     * any send bigger than the daily budget was deleted, not delayed —
     * "arrives tomorrow" is only true with attempts left to arrive on.
     */
    public int $tries = 5;

    public function __construct(public int $userId) {}

    /**
     * The daily budget lives here rather than on the notification, because this
     * is the thing the queue can release and retry. Transactional mail
     * deliberately carries no such middleware — the headroom under the
     * provider's cap is what it spends.
     *
     * @return list<object>
     */
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

        /*
         * Re-checked HERE, not just when the batch was built.
         *
         * A send of any size takes minutes to drain, and the throttle can push
         * a job into tomorrow. Somebody who unsubscribes in between must not
         * still receive the email their click was meant to prevent — which is
         * exactly the case a reader notices and reports as the unsubscribe
         * being broken.
         */
        if ($user === null || ! $user->newsletter_opt_in || $user->email_verified_at === null) {
            return;
        }

        $digest = WeeklyDigest::for($user);

        /*
         * The model call belongs HERE, one reader at a time, rather than
         * anywhere that would write once and send to everybody: a recap in
         * somebody's own register is the entire feature, and the register is a
         * property of the reader.
         *
         * It returns null on every failure it can have — flag closed, budget
         * spent, API down, copy that failed the sweep — and the template
         * answers null with the deterministic copy. Nothing here can cost this
         * reader their email.
         */
        $recap = app(RecapWriter::class)->for($user, $digest);

        $user->notify(new WeeklyNewsletter($digest, $recap));
    }
}
