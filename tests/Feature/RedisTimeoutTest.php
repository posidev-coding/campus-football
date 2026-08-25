<?php

/*
 * A Redis connection with no timeout is a sixty-second page hang waiting for
 * a cache restart.
 *
 * phpredis falls back to PHP's `default_socket_timeout` when `timeout` and
 * `read_timeout` are unset, and that default is 60. Production spent exactly
 * that on `/games/{game}` three times in two nights before throwing a
 * RedisException out of PhpRedisConnector::establishConnection() — a full
 * minute of a request worker, on a managed cache doing something entirely
 * routine.
 *
 * The retry and backoff settings cannot cover it: they only start once the
 * first attempt gives up. So the ceiling has to be declared, and a new
 * connection added later must declare one too — which is what this asserts.
 */
it('gives every redis connection a finite connect and read timeout', function () {
    $connections = collect(config('database.redis'))
        ->except(['client', 'options'])
        ->filter(fn ($config): bool => is_array($config));

    expect($connections)->not->toBeEmpty();

    $offenders = $connections
        ->filter(fn (array $config): bool => ($config['timeout'] ?? 0) <= 0
            || ($config['read_timeout'] ?? 0) <= 0)
        ->keys()
        ->all();

    expect($offenders)->toBe([]);
});

it('keeps those timeouts short enough to fail into the retry', function () {
    /*
     * Nothing in this application issues a blocking Redis command —
     * `queue.redis.block_for` is null and there is no BLPOP anywhere — so a
     * read that outlives a few seconds is a stall, not work. Ten is the
     * outer bound where holding a request worker stops being defensible.
     */
    $slowest = collect(config('database.redis'))
        ->except(['client', 'options'])
        ->filter(fn ($config): bool => is_array($config))
        ->flatMap(fn (array $config): array => [$config['timeout'] ?? 0, $config['read_timeout'] ?? 0])
        ->max();

    expect($slowest)->toBeLessThanOrEqual(10)
        ->and(config('queue.connections.redis.block_for'))->toBeNull();
});
