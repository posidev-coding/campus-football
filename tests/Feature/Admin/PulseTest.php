<?php

use App\Models\User;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Ingests\RedisIngest;
use Laravel\Pulse\Recorders;

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
