<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Keep bulk mail inside a daily budget, by RELEASING a job rather than sleeping
 * in it — the same shape as ThrottleEspn, for the same reason.
 *
 * The budget exists because of how the provider counts. Brevo's free tier is
 * 300 emails a DAY shared between marketing and transactional, so a newsletter
 * going out to everybody does not merely risk being cut off partway: it can
 * spend the day's allowance and leave a password reset with nowhere to go.
 * Somebody locked out of their account because a newsletter went first is the
 * worst possible trade, and it would look like an auth bug rather than a mail
 * one.
 *
 * So `cfb.mail_daily_budget` sits BELOW the provider's ceiling and only bulk
 * mail is counted against it. The headroom is what transactional spends, and
 * nothing transactional carries this middleware. Same reasoning as
 * ESPN_RATE_LIMIT: the number is ours, not theirs, and it is chosen to leave
 * room rather than to match what we are allowed.
 *
 * A released job comes back tomorrow rather than in a minute, because the
 * window is a day: there is nothing to gain from retrying sooner, and the
 * limiter's own `availableIn` says exactly how long that is.
 */
class ThrottleMail
{
    public const KEY = 'mail:bulk';

    /**
     * The window, in seconds, spelled out rather than derived.
     *
     * `now()->addDay()->diffInSeconds()` looks like the obvious way to say this
     * and returns NEGATIVE 86400 — Carbon 3 made the diff methods signed, so
     * the interval is measured backwards from tomorrow to now. A negative decay
     * expires the limiter key the instant it is written, so every attempt reads
     * zero and the throttle silently permits everything. It fails open, which
     * is the worst direction for a guard whose whole job is protecting somebody
     * else's password reset.
     */
    private const WINDOW = 86400;

    public function handle(object $job, Closure $next): mixed
    {
        $budget = (int) config('cfb.mail_daily_budget');

        if ($budget <= 0) {
            return $next($job);
        }

        if (RateLimiter::tooManyAttempts(self::KEY, $budget)) {
            /*
             * +1 so a job cannot wake a hair early, fail the same check and
             * bounce straight back. A fixed window means the answer does not
             * improve by asking again sooner.
             */
            $job->release(RateLimiter::availableIn(self::KEY) + 1);

            return null;
        }

        /*
         * Recorded BEFORE the send, not after.
         *
         * A send that throws still consumed the provider's allowance — the
         * message left, whatever the exception says about what happened next —
         * so counting on success would let a run of failures overspend the
         * budget silently. Erring toward under-sending is the right direction
         * when the thing being protected is password resets.
         */
        RateLimiter::hit(self::KEY, self::WINDOW);

        return $next($job);
    }
}
