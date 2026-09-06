<?php

use App\Actions\RecordActivity;
use App\Jobs\ShipActivityBatch;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

/*
 * Phase 10 of docs/plans/analytics.md: the cold tier's write path, and the
 * only part of it that lives in this repository.
 *
 * THE WHOLE THING IS OFF UNLESS CONFIGURED, and most of what is asserted here
 * is that being off is quiet: no job, no request, no failing ledger row every
 * five minutes for a feature nobody finished turning on. Nothing above this
 * reads R2 back — deleting the phase changes nothing — so the archive going
 * missing must never cost the drain, the table or the prune.
 */

beforeEach(function () {
    Redis::connection('pulse')->flushdb();

    Bus::fake();
    Http::preventStrayRequests();
});

/** Both halves set, which is the only state that ships anything. */
function pipelinesConfigured(string $url = 'https://pipelines.example/events'): void
{
    config([
        'services.cloudflare.pipelines.events_url' => $url,
        'services.cloudflare.pipelines.token' => 'a-pipelines-token',
    ]);
}

describe('the gate', function () {
    it('dispatches nothing when the endpoint is unset, which is everywhere', function () {
        config(['services.cloudflare.pipelines.token' => 'a-pipelines-token']);

        ShipActivityBatch::ship([['route' => 'home']], config('services.cloudflare.pipelines.events_url'));

        Bus::assertNothingDispatched();
    });

    it('treats a URL with no token as off rather than as a broken shipment', function () {
        /*
         * The endpoint requires the bearer, so a half-set pair could only ever
         * 401 — and it would do it once per drain, writing a failing feed_runs
         * row every five minutes. Half-configured is a misconfiguration, not a
         * half-measure.
         */
        config([
            'services.cloudflare.pipelines.events_url' => 'https://pipelines.example/events',
            'services.cloudflare.pipelines.token' => null,
        ]);

        ShipActivityBatch::ship([['route' => 'home']], config('services.cloudflare.pipelines.events_url'));

        Bus::assertNothingDispatched();
    });

    it('sends no request for a quiet five minutes', function () {
        // An empty batch is a real state and it does not need an HTTP call to
        // say so.
        pipelinesConfigured();

        ShipActivityBatch::ship([], config('services.cloudflare.pipelines.events_url'));

        Bus::assertNothingDispatched();
    });

    it('queues the batch once both halves are set', function () {
        pipelinesConfigured();

        ShipActivityBatch::ship([['route' => 'home']], config('services.cloudflare.pipelines.events_url'));

        Bus::assertDispatched(ShipActivityBatch::class, fn (ShipActivityBatch $job): bool => $job->rows === [['route' => 'home']]
            && $job->url === 'https://pipelines.example/events'
            && $job->token === 'a-pipelines-token');
    });
});

describe('the request', function () {
    it('posts the batch as a JSON array behind a bearer token', function () {
        Http::fake(['pipelines.example/*' => Http::response('', 200)]);

        $rows = [['route' => 'home'], ['route' => 'scoreboard']];

        (new ShipActivityBatch($rows, 'https://pipelines.example/events', 'a-pipelines-token'))->handle();

        Http::assertSent(function ($request) use ($rows): bool {
            return $request->url() === 'https://pipelines.example/events'
                && $request->hasHeader('Authorization', 'Bearer a-pipelines-token')
                && $request->data() === $rows;
        });
    });

    it('throws on a rejected batch, so the failure reaches the ledger', function () {
        /*
         * Swallowed on the sensor's WRITE path and thrown here, deliberately.
         * This runs on a worker with `Queue::failing` behind it, and a cold
         * tier that quietly stopped archiving looks exactly like one that is
         * working.
         */
        Http::fake(['pipelines.example/*' => Http::response('nope', 422)]);

        expect(fn () => (new ShipActivityBatch([['route' => 'home']], 'https://pipelines.example/events', 'tok'))->handle())
            ->toThrow(RuntimeException::class, '422');
    });
});

describe('the drain', function () {
    it('ships what it read, after the rows are already in MySQL', function () {
        /*
         * ORDER IS THE POINT. The archive can never be ahead of the table it
         * is an archive of, so the dispatch happens after `insertOrIgnore` has
         * returned — and it ships what was READ rather than what was newly
         * written, because a re-read of an already-written entry is the drain
         * working across a crash, not nothing to archive.
         */
        pipelinesConfigured();

        $this->actingAs(User::factory()->create())->get(route('scoreboard'))->assertOk();

        app(RecordActivity::class)->drain();

        Bus::assertDispatched(ShipActivityBatch::class, function (ShipActivityBatch $job): bool {
            return count($job->rows) === 1 && $job->rows[0]['route'] === 'scoreboard';
        });
    });

    it('ships a re-read batch that insertOrIgnore wrote nothing for', function () {
        /*
         * `insertOrIgnore` returns how many rows were NEW. After a crash
         * between the insert and the XDEL, the next drain re-reads entries
         * that are already in MySQL and writes zero — and those rows still
         * need archiving, because the first pass died before it dispatched.
         *
         * So the ship reads `$rows`, not `$written`. Pipelines is append-only,
         * and a duplicate row in an archive nothing joins on is cheaper than a
         * batch that silently never left.
         */
        pipelinesConfigured();

        $this->actingAs(User::factory()->create())->get(route('scoreboard'))->assertOk();

        // The row the crashed pass already landed, claiming the entry's
        // stream id — which is the unique index insertOrIgnore collides on.
        $stream = Redis::connection('pulse')->xRange(RecordActivity::STREAM, '-', '+');

        ActivityEvent::factory()->create(['stream_id' => (string) array_key_first($stream)]);

        expect(app(RecordActivity::class)->drain())->toBe(0);

        Bus::assertDispatched(ShipActivityBatch::class, fn (ShipActivityBatch $job): bool => count($job->rows) === 1);
    });

    it('drains exactly as it always did when the tier is off', function () {
        // The proof that deleting this phase changes nothing above it.
        $this->actingAs(User::factory()->create())->get(route('scoreboard'))->assertOk();

        expect(app(RecordActivity::class)->drain())->toBe(1);

        Bus::assertNothingDispatched();
    });
});
