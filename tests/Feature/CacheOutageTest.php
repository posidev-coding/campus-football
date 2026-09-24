<?php

use App\Actions\PublishSlate;
use App\Enums\ContestMode;
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

describe('a signed-in screen, while the cache store is down', function () {
    it('renders My Picks from the rows, with the nav dot honestly lit', function () {
        config()->set('cfb.pickem_open', true);

        // A published slate with no picks is a week that still needs the
        // reader, so the dot has to be ON. A default would read false, so a
        // lit dot proves the rows were read.
        [$commissioner, , $contest] = pickemContest(ContestMode::Classic);
        app(PublishSlate::class)->handle($commissioner, pickemDraftSlate($contest));

        Log::spy();
        breakCacheStore();

        // /picks names every read the app layout needs: the calendar under
        // the pulse, TeamGlance under the header search, and the dot.
        $this->actingAs($commissioner)
            ->get(route('pickem.home'))
            ->assertOk()
            ->assertSee('Picks waiting');

        foreach (['calendar:season:', 'glance:held:', 'glance:ranks', 'pickem-pulse:dot:'.$commissioner->id] as $key) {
            Log::shouldHaveReceived('warning')
                ->withArgs(fn (string $message, array $context) => str_starts_with($context['key'], $key))
                ->atLeast()->once();
        }
    });
});

describe('Remember::filledOrSource', function () {
    it('never stores an empty answer, so a draining sync cannot pin one', function () {
        $computed = 0;
        // An empty LIST, not null: a null is uncacheable by construction, so
        // a test built on one passes whether or not the guard holds.
        $compute = function () use (&$computed): array {
            $computed++;

            return [];
        };

        Remember::filledOrSource('probe', 60, $compute);
        Remember::filledOrSource('probe', 60, $compute);

        expect($computed)->toBe(2)
            ->and(Cache::has('probe'))->toBeFalse();
    });

    it('reads the source when the store is down, and never masks the source failing', function () {
        breakCacheStore();

        expect(Remember::filledOrSource('probe', 60, fn (): array => [2026]))->toBe([2026])
            ->and(fn () => Remember::filledOrSource('probe', 60, fn () => throw new RuntimeException('database is down')))
            ->toThrow(RuntimeException::class, 'database is down');
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
