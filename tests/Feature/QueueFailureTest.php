<?php

use App\Jobs\FetchGameSummary;
use App\Models\FeedRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/*
 * The queue-failure sensor.
 *
 * Pulse's Exceptions recorder sees the THROW. It does not see the job — and on
 * Laravel Cloud the managed queues keep `failed_jobs` entirely to themselves,
 * so the app cannot read its own failures there at all. Without the
 * Queue::failing hook in AppServiceProvider, a job that dies in production is
 * invisible to every screen we own.
 */

/** Dies on purpose. Declared here so no production class has to. */
class FailingProbeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('the probe job always dies');
    }
}

it('records a job that dies, where the app can actually read it', function () {
    // The sync driver fires JobFailed and then rethrows, which is the whole
    // path under test.
    try {
        dispatch(new FailingProbeJob);
    } catch (RuntimeException) {
        //
    }

    $run = FeedRun::latestFor('job:FailingProbeJob');

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(FeedRun::FAILED)
        ->and($run->error)->toContain('the probe job always dies')
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();
});

it('never replaces the real exception with a bookkeeping one', function () {
    // The listener swallows its own errors on purpose: it runs inside the
    // handler for something that has already failed, and a throw there would
    // lose the actual cause and report the ledger instead.
    expect(fn () => dispatch(new FailingProbeJob))
        ->toThrow(RuntimeException::class, 'the probe job always dies');
});

it('keeps the job rows apart from the command rows', function () {
    // `job:` is the prefix the Sync Health table shows, and the thing that
    // makes "a command failed" and "a job failed" distinguishable at a glance
    // in one ledger.
    FeedRun::jobFailed(FetchGameSummary::class, 'ESPN returned 403');

    expect(FeedRun::latestFor('job:FetchGameSummary'))->not->toBeNull()
        ->and(FeedRun::latestFor('FetchGameSummary'))->toBeNull();
});
