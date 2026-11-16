<?php

namespace App\Console\Concerns;

use App\Models\FeedRun;
use App\Services\Espn\EspnClient;
use Closure;
use Throwable;

/**
 * Wraps a sync command's work in a feed_runs row, so "did it run, what did it
 * write, what did it spend" survives the process instead of scrolling off a
 * console nobody was watching.
 *
 * The exception is RETHROWN after being recorded: the scheduler's exit code
 * and the Cloud dashboard's failure signal both still mean what they meant.
 * Recording is bookkeeping, never a rescue.
 */
trait TracksFeedRun
{
    /**
     * @param  Closure(): (int|array{records: int, batch_id: ?string})  $work
     *                                                                         Returns the records written, or ['records' => …, 'batch_id' => …]
     *                                                                         for a fan-out command whose real work drains through a batch.
     */
    protected function trackRun(string $command, ?int $year, Closure $work): int
    {
        // The singleton client carries the counter, so the requests recorded
        // here are exactly the ones the console line already prints.
        $espn = app(EspnClient::class);
        $espn->resetCallCount();

        $run = FeedRun::begin($command, $year);
        $started = microtime(true);

        try {
            $result = $work();
        } catch (Throwable $e) {
            $run->fail(
                $e->getMessage(),
                $espn->callCount(),
                (int) ((microtime(true) - $started) * 1000),
            );

            throw $e;
        }

        $records = is_array($result) ? (int) $result['records'] : (int) $result;

        $run->complete(
            $records,
            $espn->callCount(),
            (int) ((microtime(true) - $started) * 1000),
            is_array($result) ? ($result['batch_id'] ?? null) : null,
        );

        return $records;
    }
}
