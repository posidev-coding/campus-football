<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

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
}
