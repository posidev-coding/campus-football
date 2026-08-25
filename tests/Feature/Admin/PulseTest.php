<?php

use App\Models\User;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Ingests\RedisIngest;
use Laravel\Pulse\Recorders;
use Laravel\Pulse\Support\CacheStoreResolver;
use Livewire\Livewire;

describe('the dashboard gate', function () {
    it('refuses a guest', function () {
        $this->get('/pulse')->assertForbidden();
    });

    it('refuses a signed-in reader', function () {
        // Pulse's own gate answers `environment('local')`, which would let any
        // authenticated developer in locally and nobody in at all in
        // production. Ours answers isAdmin() in every environment.
        $this->actingAs(User::factory()->create())->get('/pulse')->assertForbidden();
    });

    it('admits an admin', function () {
        $admin = User::factory()->create();
        $admin->forceFill(['admin' => true])->save();

        $this->actingAs($admin)->get('/pulse')->assertOk();
    });
});

describe('the cards themselves', function () {
    /*
     * Rendering `/pulse` proves NOTHING about these. Every card is `#[Lazy]`,
     * so the page returns 200 with placeholders and the cards run on a later
     * Livewire round trip — the same trap as a Filament widget, whose content
     * is not in its page's HTML. The first version of this file asserted the
     * 200 and shipped a dashboard that fataled on the second request.
     *
     * What it fataled on: Pulse caches each card's result as an OBJECT, and
     * Laravel 13's `cache.serializable_classes => false` hands every object
     * back from a serializing store as `__PHP_Incomplete_Class`. `->hits` on
     * that is an ErrorException. The Cache card is simply the one that
     * dereferences unconditionally, so it broke first and the rest were queued
     * up behind it.
     */
    beforeEach(function () {
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['admin' => true])->save();

    });

    it('renders every card we enabled, twice', function (string $component) {
        /*
         * TWICE, and `withoutLazyLoading()` before EACH — both halves are
         * load-bearing, and this test was green and worthless without them.
         *
         * Twice, because the first call POPULATES the result cache and returns
         * the closure's own value; the round trip through the store only
         * happens on the second. It is the same "call it twice" rule
         * support.md states for anything cached.
         *
         * Before each, because `withoutLazyLoading()` applies to the NEXT
         * component only. Called once in a beforeEach, the second render
         * quietly returns the `animate-pulse` skeleton instead of running
         * render() — so the test passes by never executing the code.
         */
        Livewire::withoutLazyLoading();
        Livewire::actingAs($this->admin)->test($component)->assertOk();

        Livewire::withoutLazyLoading();
        Livewire::actingAs($this->admin)->test($component)->assertOk();
    })->with([
        'pulse.cache',
        'pulse.exceptions',
        'pulse.slow-queries',
        'pulse.slow-requests',
        'pulse.slow-jobs',
        'pulse.slow-outgoing-requests',
        'pulse.usage',
        'pulse.queues',
        'pulse.servers',
    ]);

    it('caches card results somewhere objects survive', function () {
        // `serializable_classes` is GLOBAL, not per-store — CacheManager
        // ignores a store's own config — so no Redis store escapes it and
        // relaxing it app-wide would trade the whole app's protection for one
        // admin page. The array store does not serialize at all.
        expect(config('pulse.cache'))->toBe('array')
            ->and(config('cache.serializable_classes'))->toBeFalse();

        $store = app(CacheStoreResolver::class)->store();
        $store->put('probe', [(object) ['hits' => 1]], 30);

        expect($store->get('probe')[0])->toBeInstanceOf(stdClass::class);
    });
});

describe('the ingest path', function () {
    it('buffers through Redis rather than the request path', function () {
        expect(config('pulse.ingest.driver'))->toBe('redis')
            ->and(app(Ingest::class))->toBeInstanceOf(RedisIngest::class);
    });

    it('keeps the buffer off the cache database', function () {
        // `cache:clear` flushes the cache connection's database, and it is run
        // deliberately — it re-arms the mail/SMS budgets and the ESPN limiter.
        // Sharing a database would make buffered telemetry collateral damage.
        expect(config('pulse.ingest.redis.connection'))->toBe('pulse')
            ->and(config('database.redis.pulse.database'))->not->toBe(config('database.redis.cache.database'));
    });

    it('stores where the advisor can read it', function () {
        // The whole reason Pulse beats a hosted APM here: the aggregates land
        // in our own MySQL, so `cfb:telemetry` reads them with a query.
        expect(config('pulse.storage.driver'))->toBe('database')
            ->and(config('pulse.storage.database.connection'))->toBeNull();
    });
});

describe('the recorder roster', function () {
    it('records performance, exceptions and usage', function () {
        foreach ([
            Recorders\Exceptions::class,
            Recorders\SlowJobs::class,
            Recorders\SlowOutgoingRequests::class,
            Recorders\SlowQueries::class,
            Recorders\SlowRequests::class,
            Recorders\UserJobs::class,
            Recorders\UserRequests::class,
        ] as $recorder) {
            expect(config("pulse.recorders.{$recorder}.enabled"))->toBeTrue($recorder);
        }
    });

    it('leaves the two high-volume recorders off', function () {
        // Every cache read and every job state transition would be an entry.
        // This app reads the cache on every page and fans jobs out per game,
        // per team and per week, so both would out-volume the signal.
        expect(config('pulse.recorders.'.Recorders\CacheInteractions::class.'.enabled'))->toBeFalse()
            ->and(config('pulse.recorders.'.Recorders\Queues::class.'.enabled'))->toBeFalse();
    });
});
