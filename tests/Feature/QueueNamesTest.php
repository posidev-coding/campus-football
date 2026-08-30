<?php

use Illuminate\Support\Facades\File;

/*
 * There are two queues, and the source is the only place that says so.
 *
 * A third queue named `backfill` used to carry the bulk work — the summary
 * batches, the newsletter, the pick reminders, the hand-run passes off Sync
 * Health. It bought nothing the split between `live` and `default` did not
 * already buy, and it cost a third managed queue on Cloud plus a third name
 * every `queue:work --queue=` line and every runbook had to keep repeating.
 *
 * The failure mode a queue name has is silent in both directions: a job
 * dispatched onto a name no worker consumes never runs and never errors, and
 * `Bus::fake()` will happily assert a batch onto a queue that does not exist.
 * So this asserts the SHAPE of the code, in the spirit of ScheduleReadersTest
 * — every queue a dispatch names must be one somebody is working.
 */
it('dispatches onto live and default, and onto no other queue', function () {
    $named = collect(File::allFiles(app_path()))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->flatMap(function ($file): array {
            preg_match_all("/onQueue\(\s*'([^']+)'\s*\)/", File::get($file->getPathname()), $matches);

            return $matches[1];
        })
        ->unique()
        ->sort()
        ->values()
        ->all();

    // Not a subset check: a name that is not one of these two is the bug,
    // and so is a dispatch site that has quietly stopped naming a queue.
    expect($named)->toBe(['default', 'live']);
});
