<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cache::remember for values whose emptiness is a moment, not a fact.
 *
 * The season menus are built from "which years have rows" lookups, and the
 * rows arrive asynchronously — a backfill drains through queued jobs long
 * after the command that started it exits. Cache::remember treats whatever
 * the first request computes as authoritative, so a page opened before the
 * backfill landed pinned an EMPTY year list for a full TTL while the slates
 * beside it (cached per year) healed on their own. Production served a
 * populated stats screen with a season menu holding no options.
 *
 * Same family as "never write a default when a feed returns nothing" — this
 * is that rule at the cache layer: never serve cached nothing as if it were
 * an answer.
 */
class Remember
{
    /**
     * Serve the cached value only when it is non-null and non-empty;
     * otherwise recompute, storing only a non-empty result.
     *
     * An already-cached empty is treated as a miss too, so a fix deploys
     * without a coordinated cache:clear — the next request recomputes and,
     * if the data has landed, overwrites the pinned nothing.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $compute
     * @return TValue
     */
    public static function filled(string $key, int $ttl, Closure $compute)
    {
        $cached = Cache::get($key);

        if ($cached !== null && $cached !== []) {
            return $cached;
        }

        $value = $compute();

        if ($value !== null && $value !== []) {
            Cache::put($key, $value, $ttl);
        }

        return $value;
    }

    /**
     * Cache::remember for a read the page cannot render without, where the
     * cache is only an accelerator in front of a source that is still up.
     *
     * The cache is Redis, and a Redis stall mid-render used to 500 every page:
     * `partials/head` reads the brand through the cache, Blade wrapped the
     * RedisException in a ViewException, and the brand_settings row it was
     * standing in front of never got asked (CFB-97). Here, a store that throws
     * on the read is skipped and the closure runs. The closure is the same
     * source of truth the cache would have been filled from, never a default.
     * A store that throws on the fill is skipped too, because the value is
     * already in hand.
     *
     * ONLY THE STORE CALLS ARE GUARDED. The closure runs outside every `try`,
     * so a database that is down still throws. Masking that would serve a
     * page built from nothing.
     *
     * Not silent: each failure is a warning naming the store, so the next
     * stall is as visible as the last one was. Not for a write path either.
     * This is for reads a render needs, not a way to make the app fail open.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $compute
     * @return TValue
     */
    public static function orSource(string $key, int $ttl, Closure $compute)
    {
        try {
            $cached = Cache::get($key);
        } catch (Throwable $e) {
            self::storeFailed('read', $key, $e);

            return $compute();
        }

        if ($cached !== null) {
            return $cached;
        }

        $value = $compute();

        try {
            Cache::put($key, $value, $ttl);
        } catch (Throwable $e) {
            self::storeFailed('write', $key, $e);
        }

        return $value;
    }

    private static function storeFailed(string $operation, string $key, Throwable $e): void
    {
        Log::warning("Cache store unavailable on {$operation}; read the source instead", [
            'store' => config('cache.default'),
            'key' => $key,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
