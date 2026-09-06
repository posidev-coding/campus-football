<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Throwable;

/**
 * The app's log, onto the same kind of Redis stream the clickstream uses, so
 * the cold tier can give it a home beside the events.
 *
 * WHY A STREAM AND NOT AN HTTP CALL. A log handler runs wherever logging runs
 * — inside a request, inside a queue worker, inside an exception handler that
 * is already dealing with something worse — and an HTTP call in that position
 * would add somebody else's latency to every warning the app writes. `XADD`
 * on the `pulse` connection is the same one-write transport `RecordActivity`
 * uses, and the activity drain ships it out of band.
 *
 * SWALLOW-ALL, AND SILENTLY. Every other swallow in this app writes a
 * `Log::debug` on the way past. This one must not: it IS the log handler, and
 * logging from inside a failed log write is how a Redis outage turns into
 * unbounded recursion. A log line that could not be archived is not worth a
 * single byte of noise, let alone a stack of them.
 *
 * NOT IN THE STACK BY DEFAULT. It is registered as a channel in
 * `config/logging.php` and a human adds `pipelines` to `LOG_STACK` — at which
 * point it is additive, because the stack still carries `single` and the file
 * on disk stays the log of record.
 */
class PipelinesLogHandler extends AbstractProcessingHandler
{
    /** The stream the drain reads. */
    public const STREAM = 'cfb:logs';

    /**
     * The approximate ceiling on unshipped records.
     *
     * A quarter of the clickstream's, because a log line is bigger than a
     * page view and far rarer: fifty thousand is days of an ordinary week and
     * still bounded if the endpoint is never configured, which is the state
     * this ships in.
     */
    public const MAXLEN = 50_000;

    protected function write(LogRecord $record): void
    {
        try {
            Redis::connection('pulse')->xAdd(
                self::STREAM,
                '*',
                self::fields($record),
                self::MAXLEN,
                // POSITIONAL, and `true` is the approximate trim — the same
                // note RecordActivity::push carries. A named argument through
                // the connection's `__call` does not reach phpredis, and the
                // catch below would have made that a stream that silently
                // never gets written.
                true,
            );
        } catch (Throwable) {
            // Deliberately silent — see the class docblock.
        }
    }

    /**
     * The flat dictionary a stream entry carries.
     *
     * Public so a test can hold it without a Redis, and so what it can never
     * carry is assertable: a stream field has no type, so `context` is JSON
     * and everything else is already a string.
     *
     * The RELEASE rides along for the same reason `activity_events` carries
     * it — an error is worth reading against the deploy that shipped it — and
     * it is null rather than invented when there is no stamp.
     *
     * @return array<string, string>
     */
    public static function fields(LogRecord $record): array
    {
        return [
            'level' => $record->level->getName(),
            'channel' => $record->channel,
            'message' => $record->message,
            // The array, encoded once. A failed encode (a resource, a
            // recursive structure) is an empty object rather than a thrown
            // handler — see the class docblock.
            'context' => json_encode($record->context, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}',
            'datetime' => $record->datetime->format('Y-m-d H:i:s.u'),
            'release' => Release::version() ?? '',
            'env' => (string) config('app.env'),
        ];
    }
}
