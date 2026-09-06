<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One drained batch, POSTed to a Cloudflare Pipelines endpoint.
 *
 * The cold tier's whole write path. Pipelines takes a JSON array on an HTTP
 * endpoint with no Worker in front of it and lands it as an Iceberg table in
 * R2 Data Catalog, so the archive costs one request per drain rather than a
 * second piece of infrastructure.
 *
 * QUEUED, NEVER INLINE IN THE DRAIN. The drain runs every five minutes on a
 * wake the live tier already pays for, and a slow or hanging HTTP call inside
 * it would stretch that cadence — the buffer backs up behind a request to
 * somebody else's service, which is the one failure a cold tier must not be
 * able to cause. On `default` for the same reason: this is the least urgent
 * work in the app and it may wait behind a pick reminder.
 *
 * A FAILURE IS A LEDGER ROW AND NOTHING ELSE. The existing `Queue::failing`
 * hook writes `job:ShipActivityBatch` into `feed_runs`, which is where every
 * other job's failure already goes. Nothing retries into the drain, nothing
 * is re-read, and MySQL is untouched — the rows were already written before
 * this was ever dispatched, and losing an archive copy costs the archive, not
 * the data.
 *
 * OFF UNLESS CONFIGURED. {@see ship()} is the only door, and it dispatches
 * nothing when the URL or the token is missing.
 */
class ShipActivityBatch implements ShouldQueue
{
    use Queueable;

    /**
     * Comfortably under the queue's `retry_after` (90s), the relationship
     * every job here keeps: a timeout longer than `retry_after` is how a job
     * gets released and re-run while the first copy is still going.
     */
    public int $timeout = 30;

    /**
     * Three attempts with room between them. Pipelines is an HTTP endpoint
     * somebody else operates, and a minute of theirs should not cost us a
     * batch.
     */
    public int $tries = 3;

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public array $rows,
        public string $url,
        public string $token,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * Dispatch a batch, or do nothing at all.
     *
     * THE ONE GATE, and it reads config rather than env so a cached config is
     * the truth. An empty batch is not dispatched either: a quiet five minutes
     * is a real state and it does not need a request to say so.
     *
     * A URL with no token counts as OFF rather than as a broken shipment. The
     * endpoint requires the bearer, so a half-set pair would queue a job per
     * drain that could only ever 401 — a failing ledger row every five minutes
     * for a feature nobody finished turning on.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function ship(array $rows, ?string $url): void
    {
        $token = config('services.cloudflare.pipelines.token');

        if ($rows === [] || ! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            return;
        }

        self::dispatch($rows, $url, $token);
    }

    public function handle(): void
    {
        $response = Http::withToken($this->token)
            ->timeout(15)
            ->asJson()
            ->post($this->url, $this->rows);

        /*
         * Thrown rather than swallowed, unlike everything on the sensor's
         * WRITE path. This runs on a queue worker with a ledger behind it, so
         * an endpoint answering 4xx is exactly the thing `feed_runs` exists to
         * make visible — a cold tier that quietly stopped archiving looks
         * identical to one that is working.
         */
        if ($response->failed()) {
            throw new RuntimeException(
                'Pipelines rejected a batch of '.count($this->rows).' rows: '.$response->status(),
            );
        }
    }
}
