<?php

use App\Models\BrandSetting;
use App\Support\Remember;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/*
 * A Redis stall mid-render used to 500 every page on the site (CFB-97).
 * `partials/head` is on every layout and reads the brand through the cache;
 * the cache is Redis; Blade wrapped the RedisException in a ViewException. The
 * brand_settings row the cache stood in front of was up the whole time.
 */

/**
 * Put the cache on Redis, and point Redis at a closed port.
 *
 * The suite runs the cache on `array`, so nothing here would touch Redis
 * without the first line. And the manager snapshots its config at
 * construction, so `config()->set()` on a connection changes nothing — a test
 * written that way passes against a perfectly healthy Redis.
 */
function breakCacheStore(): void
{
    app()->singleton('redis', fn ($app) => new RedisManager($app, 'phpredis', [
        'client' => 'phpredis',
        'options' => ['prefix' => 'cfb-outage-'],
        'cache' => ['host' => '127.0.0.1', 'port' => 65_000, 'database' => 1, 'timeout' => 0.2],
        'default' => ['host' => '127.0.0.1', 'port' => 65_000, 'database' => 0, 'timeout' => 0.2],
        'pulse' => ['host' => '127.0.0.1', 'port' => 65_000, 'database' => 15, 'timeout' => 0.2],
    ]));

    Redis::clearResolvedInstances();
    Cache::forgetDriver('redis');
    config(['cache.default' => 'redis']);
}

describe('the layout, while the cache store is down', function () {
    it('renders the stored brand from its row instead of a 500', function () {
        // A name only the ROW can supply, so a pass proves the source of
        // truth was read rather than the shipped default.
        BrandSetting::current()->update(['name' => 'Rocky Top Picks']);

        Log::spy();
        breakCacheStore();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<title>Rocky Top Picks</title>', escape: false);

        // Caught is not the same as hidden: the next stall has to be as
        // visible to the advisor as the last one was.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'Cache store unavailable')
                && $context['store'] === 'redis'
                && $context['key'] === 'brand:settings')
            ->atLeast()->once();
    });
});

describe('Remember::orSource', function () {
    it('never masks the source failing — only the store', function () {
        breakCacheStore();

        expect(fn () => Remember::orSource('probe', 60, fn () => throw new RuntimeException('database is down')))
            ->toThrow(RuntimeException::class, 'database is down');
    });

    it('caches through a healthy store exactly as Cache::remember does', function () {
        $computed = 0;
        $compute = function () use (&$computed): bool {
            $computed++;

            return false;
        };

        // `false` is an answer, not a miss — the nav dot caches it.
        expect(Remember::orSource('probe', 60, $compute))->toBeFalse()
            ->and(Remember::orSource('probe', 60, $compute))->toBeFalse()
            ->and($computed)->toBe(1);
    });
});
