<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Keep SMS inside a daily budget, by RELEASING a job rather than sleeping in it.
 *
 * Third of its kind after ThrottleEspn and ThrottleMail, and the first where the
 * ceiling is MONEY rather than somebody else's rate limit. A runaway loop
 * against the ESPN feed is rude; the same loop here is roughly a cent a message
 * once the carrier surcharge is counted, billed until somebody notices.
 *
 * That changes what the budget is FOR. The mail budget exists to leave headroom
 * for transactional mail inside a shared provider cap; this one exists so a bug
 * cannot become an invoice. It should be set to the largest send that is
 * plausible on purpose, not to what the account is capable of.
 */
class ThrottleSms
{
    public const KEY = 'sms:bulk';

    /**
     * A day in seconds, spelled out.
     *
     * NOT `now()->addDay()->diffInSeconds()`, which is the obvious way to write
     * it and returns NEGATIVE 86400 under Carbon 3's signed diffs — expiring the
     * limiter key the instant it is written, so every attempt reads zero and the
     * throttle silently permits everything. ThrottleMail shipped with that bug
     * and a test caught it; it is repeated here as a comment rather than as a
     * mistake.
     */
    private const WINDOW = 86400;

    public function handle(object $job, Closure $next): mixed
    {
        $budget = (int) config('cfb.sms_daily_budget');

        if ($budget <= 0) {
            return $next($job);
        }

        if (RateLimiter::tooManyAttempts(self::KEY, $budget)) {
            // +1 so a job cannot wake a hair early, fail the same check and
            // bounce again. A fixed window does not improve by asking sooner.
            $job->release(RateLimiter::availableIn(self::KEY) + 1);

            return null;
        }

        /*
         * Counted BEFORE the send. A message that throws may still have left —
         * and a carrier still charges for it — so counting on success would let
         * a run of failures overspend. Under-sending is the safe direction when
         * the thing on the other side is a bill.
         */
        RateLimiter::hit(self::KEY, self::WINDOW);

        return $next($job);
    }
}
