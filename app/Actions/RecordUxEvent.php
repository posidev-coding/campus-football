<?php

namespace App\Actions;

use App\Enums\UxSignal;
use App\Models\UxEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Count one funnel signal — in Redis, on the request path, never in MySQL.
 *
 * The two flows this measures are picking and onboarding, the two most
 * latency-sensitive in the product, so a row per event would buy a MySQL
 * write in both to learn a number only ever read in aggregate. A hash field
 * per (day, signal) costs one `HINCRBY` and answers the same question.
 *
 * On Redis DB 2, the telemetry database, beside Pulse's ingest stream and for
 * the same reason: `cache:clear` flushes DB 1 and is run deliberately, and a
 * day's funnel must not be collateral damage of a clear.
 *
 * EVERY FAILURE IS SWALLOWED. A counter is never worth a 500 on a pick — this
 * measures the product, it is not part of it.
 */
class RecordUxEvent
{
    /**
     * How long an un-rolled day survives in Redis.
     *
     * Spelled out, not derived. Long enough that a rollup which failed for a
     * week still finds every day it missed; short enough that a rollup which
     * stopped forever does not grow without bound.
     */
    public const KEEP_SECONDS = 1_209_600;

    /** The set of days holding counts — what the rollup iterates. */
    public const DAYS_KEY = 'ux:days';

    /** How long a once-per-day dedupe key survives. Spelled out, not derived. */
    public const SEEN_SECONDS = 172_800;

    public function handle(UxSignal $signal, ?CarbonInterface $on = null): void
    {
        try {
            // The league's timezone, not UTC: a Saturday-night pick at 01:00
            // UTC Sunday belongs to Saturday's funnel, and the whole product
            // reads its clock in Eastern.
            $day = ($on ?? now())->timezone(config('cfb.timezone'))->format('Y-m-d');

            $redis = Redis::connection('pulse');

            $redis->hincrby(self::dayKey($day), $signal->value, 1);
            $redis->expire(self::dayKey($day), self::KEEP_SECONDS);

            // A SET of days rather than a KEYS scan: the rollup must never
            // walk the keyspace of a database Pulse is streaming into.
            //
            // The member is passed as a SCALAR, not wrapped in an array.
            // phpredis stringifies an array argument to the literal "Array" —
            // silently, with SADD still returning 1 the first time — so every
            // day would collapse into one member named "Array" and the rollup
            // would find nothing to roll up.
            $redis->sadd(self::DAYS_KEY, $day);
            $redis->expire(self::DAYS_KEY, self::KEEP_SECONDS);
        } catch (Throwable $e) {
            Log::debug('Could not count a UX signal.', ['signal' => $signal->value, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Count a signal at most once per day per subject.
     *
     * Needed because "opened a slate" is a MOUNT, and a Livewire navigate hop
     * re-mounts: without this the numerator inflates and the abandonment rate
     * derived from it reads worse than the truth.
     *
     * The subject carries a user id — or, where the person does not have one
     * yet, a HASH of their session id, never the raw value, which is the
     * session cookie. Either way it is the only place in this pipeline
     * anything identifying appears. It lives in a Redis set with a two-day
     * expiry, is never persisted, and never reaches `ux_events` or the
     * telemetry snapshot — it is a deduplication key, not a record of
     * anybody.
     */
    public function handleOnce(UxSignal $signal, string $subject, ?CarbonInterface $on = null): void
    {
        try {
            $day = ($on ?? now())->timezone(config('cfb.timezone'))->format('Y-m-d');

            $redis = Redis::connection('pulse');
            $key = "ux:seen:{$day}:{$signal->value}";

            // SADD returns 0 when the member was already there — the whole
            // check and the whole write in one round trip. Scalar member, not
            // an array: phpredis stringifies an array to "Array", which would
            // make every subject the same subject and dedupe everybody away.
            if ((int) $redis->sadd($key, $subject) !== 1) {
                return;
            }

            $redis->expire($key, self::SEEN_SECONDS);
        } catch (Throwable $e) {
            Log::debug('Could not dedupe a UX signal.', ['signal' => $signal->value, 'error' => $e->getMessage()]);

            return;
        }

        $this->handle($signal, $on);
    }

    /**
     * What today has counted so far, before the nightly rollup persists it.
     *
     * Any report that leaves this out is blind to the last few hours — which
     * is exactly the window somebody is reading a report to understand.
     */
    public function todayCount(UxSignal $signal): int
    {
        try {
            $day = now()->timezone(config('cfb.timezone'))->format('Y-m-d');

            return (int) Redis::connection('pulse')->hget(self::dayKey($day), $signal->value);
        } catch (Throwable) {
            return 0;
        }
    }

    public static function dayKey(string $day): string
    {
        return "ux:{$day}";
    }

    /**
     * Persist every day that is finished, and drop it from Redis.
     *
     * Today is left alone deliberately: a partial day rolled up at 04:50 and
     * again tomorrow would be right only by accident. `updateOrCreate` on
     * (day, signal) makes a re-run of an already-persisted day a correction
     * rather than a doubling.
     *
     * @return int the number of (day, signal) rows written
     */
    public function rollUp(): int
    {
        $redis = Redis::connection('pulse');
        $today = now()->timezone(config('cfb.timezone'))->format('Y-m-d');

        $written = 0;

        foreach ((array) $redis->smembers(self::DAYS_KEY) as $day) {
            if ($day >= $today) {
                continue;
            }

            foreach ((array) $redis->hgetall(self::dayKey($day)) as $signal => $count) {
                // A signal retired from the enum is dropped rather than
                // persisted: the vocabulary is the code's, not Redis's.
                if (UxSignal::tryFrom((string) $signal) === null) {
                    continue;
                }

                UxEvent::updateOrCreate(
                    ['day' => $day, 'signal' => $signal],
                    ['count' => (int) $count],
                );

                $written++;
            }

            $redis->del(self::dayKey($day));
            $redis->srem(self::DAYS_KEY, $day);
        }

        return $written;
    }
}
