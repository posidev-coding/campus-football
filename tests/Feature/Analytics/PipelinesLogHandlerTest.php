<?php

use App\Actions\RecordActivity;
use App\Jobs\ShipActivityBatch;
use App\Support\PipelinesLogHandler;
use App\Support\Release;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Monolog\Level;
use Monolog\LogRecord;

/*
 * Phase 10 of docs/plans/analytics.md: the app's log onto the same kind of
 * Redis stream the clickstream uses, so the cold tier can give it a home.
 *
 * Real Redis on database 15, the same choice the drain suite makes: the
 * guarantee here is about a boundary, and a fake that only differs under test
 * is where this class of bug hides.
 *
 * NOT IN LOG_STACK BY DEFAULT. A human adds `pipelines` to the stack once the
 * endpoint is set, and it is additive when they do — `single` stays and the
 * file on disk remains the log of record.
 */

beforeEach(function () {
    Redis::connection('pulse')->flushdb();
});

/** Point the pulse connection at a closed port. The manager snapshots config. */
function breakPipelinesRedis(): void
{
    app()->singleton('redis', fn ($app) => new RedisManager($app, 'phpredis', [
        'client' => 'phpredis',
        'pulse' => ['host' => '127.0.0.1', 'port' => 65_000, 'database' => 15, 'timeout' => 0.2],
    ]));

    Redis::clearResolvedInstances();
}

function logRecord(string $message = 'something happened', array $context = []): LogRecord
{
    return new LogRecord(
        new DateTimeImmutable('2026-09-06 12:00:00'),
        'testing',
        Level::Warning,
        $message,
        $context,
    );
}

describe('the handler', function () {
    it('writes one stream entry per record', function () {
        (new PipelinesLogHandler)->handle(logRecord());

        $entries = Redis::connection('pulse')->xRange(PipelinesLogHandler::STREAM, '-', '+');

        expect($entries)->toHaveCount(1);

        $fields = (array) reset($entries);

        expect($fields['level'])->toBe('WARNING')
            ->and($fields['channel'])->toBe('testing')
            ->and($fields['message'])->toBe('something happened')
            ->and($fields['env'])->toBe(config('app.env'));
    });

    it('carries the release, so an error can be read against the deploy that shipped it', function () {
        // The reason `activity_events` carries it too. Empty rather than
        // invented when there is no stamp on the checkout.
        $fields = PipelinesLogHandler::fields(logRecord());

        expect($fields['release'])->toBe(Release::version() ?? '');
    });

    it('encodes the context rather than letting it throw the handler', function () {
        // A resource is not JSON-encodable, and a log handler that throws on
        // one turns a warning somewhere else into a fatal here.
        $handle = fopen('php://memory', 'r');

        $fields = PipelinesLogHandler::fields(logRecord(context: ['stream' => $handle]));

        fclose($handle);

        expect($fields['context'])->toBeString()
            ->and(json_decode($fields['context'], true))->toBeArray();
    });
});

describe('when Redis cannot be reached', function () {
    it('swallows the failure whole', function () {
        breakPipelinesRedis();

        expect(fn () => (new PipelinesLogHandler)->handle(logRecord()))->not->toThrow(Exception::class);
    });

    it('survives a real log call routed through the channel', function () {
        // End to end rather than against the class: this is also what proves
        // the `pipelines` channel in config/logging.php is wired to this
        // handler at all, which a direct `new` would never notice.
        config(['logging.default' => 'pipelines']);
        app()->forgetInstance('log');
        Log::clearResolvedInstances();

        breakPipelinesRedis();

        Log::warning('the endpoint is gone');

        expect(Log::getLogger()->getHandlers()[0])->toBeInstanceOf(PipelinesLogHandler::class);
    });

    it('says nothing about it, which is what stops the recursion', function () {
        /*
         * THE ONE SWALLOW IN THIS APP THAT WRITES NO Log::debug ON THE WAY
         * PAST. It IS the log handler: a line logged from inside a failed log
         * write goes straight back through this handler, fails again, and logs
         * again, until the stack is gone.
         *
         * Pinned against the SOURCE rather than by provoking it, because the
         * provoked version does not fail — it exhausts the stack and takes the
         * whole run with it, which is a worse thing to leave in a suite than a
         * grep for the rule it is enforcing.
         */
        $source = (string) file_get_contents(app_path('Support/PipelinesLogHandler.php'));

        // Comments stripped first: the docblock says the rule out loud, and
        // the assertion is about the CODE.
        $code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

        expect($code)->not->toContain('Log::');
    });
});

describe('the logs drain', function () {
    it('ships what it read and empties the stream', function () {
        Bus::fake();

        config([
            'services.cloudflare.pipelines.logs_url' => 'https://pipelines.example/logs',
            'services.cloudflare.pipelines.token' => 'a-pipelines-token',
        ]);

        (new PipelinesLogHandler)->handle(logRecord('first'));
        (new PipelinesLogHandler)->handle(logRecord('second'));

        expect(app(RecordActivity::class)->drainLogs())->toBe(2)
            ->and(Redis::connection('pulse')->xLen(PipelinesLogHandler::STREAM))->toBe(0);

        Bus::assertDispatched(ShipActivityBatch::class, function (ShipActivityBatch $job): bool {
            return $job->url === 'https://pipelines.example/logs'
                && collect($job->rows)->pluck('message')->all() === ['first', 'second'];
        });
    });

    it('reports itself on the drain command only when it has something to say', function () {
        /*
         * The log stream is empty in every deployment where nobody added
         * `pipelines` to LOG_STACK, and a "drained 0 log records" line every
         * five minutes is noise in the one place somebody reads to find out
         * whether the pipeline is alive.
         */
        $this->artisan('cfb:activity-drain')
            ->expectsOutputToContain('Wrote 0 activity events.')
            ->doesntExpectOutputToContain('log records')
            ->assertExitCode(0);

        (new PipelinesLogHandler)->handle(logRecord());

        $this->artisan('cfb:activity-drain')
            ->expectsOutputToContain('Drained 1 log records.')
            ->assertExitCode(0);
    });

    it('empties the stream even with nowhere to ship it', function () {
        /*
         * A human can add the channel to LOG_STACK without setting the
         * endpoint. Left undrained the stream would sit at its MAXLEN forever,
         * which from the outside is indistinguishable from a drain that has
         * stopped — and nothing unique is lost, because this channel is
         * additive and the same records are already on `single`.
         */
        Bus::fake();

        (new PipelinesLogHandler)->handle(logRecord());

        expect(app(RecordActivity::class)->drainLogs())->toBe(1)
            ->and(Redis::connection('pulse')->xLen(PipelinesLogHandler::STREAM))->toBe(0);

        Bus::assertNothingDispatched();
    });
});
