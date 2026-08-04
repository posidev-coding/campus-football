<?php

namespace App\Jobs\Middleware;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate-limit a queued job by RELEASING it, never by sleeping in it.
 *
 * EspnClient's own throttle blocks — `while (tooManyAttempts) usleep(250ms)` —
 * which is correct for a synchronous caller that has nowhere to defer to, and
 * actively harmful on a queue. Laravel's RateLimiter is a FIXED WINDOW, not a
 * token bucket: once the minute's allowance is spent, `availableIn` returns a
 * flat 60 seconds. So under parallel workers the blocking version behaves like
 * this:
 *
 *   240 requests burst through, then every worker spins in usleep for up to a
 *   minute, jobs hit their 60s timeout mid-wait, `retry_after` releases them,
 *   and they come back and wait again.
 *
 * Throughput goes DOWN as workers are added. Releasing instead hands the worker
 * straight back, so it picks up something else — or idles cheaply — until the
 * window rolls.
 *
 * Deliberately only CHECKS the limiter. EspnClient still records the hit, so
 * there is one key and one accounting point shared by queued and synchronous
 * callers alike, and the 240/min ceiling stays global no matter how the request
 * was made.
 */
class ThrottleEspn
{
    public function handle(object $job, \Closure $next): mixed
    {
        $limit = (int) config('espn.http.rate_limit');

        if ($limit > 0 && RateLimiter::tooManyAttempts('espn-api', $limit)) {
            /*
             * +1 so a job cannot wake a hair early, fail the same check and
             * bounce again. A fixed window means there is nothing to gain from
             * retrying sooner.
             */
            $job->release(RateLimiter::availableIn('espn-api') + 1);

            return null;
        }

        return $next($job);
    }
}
